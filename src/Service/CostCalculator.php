<?php
namespace App\Service;

use App\Entity\RecipeCostCache;
use App\Repository\RecipeRepository;
use App\Repository\IngredientPriceRepository;
use App\Repository\ConfigBoutiqueRepository;
use Doctrine\ORM\EntityManagerInterface;

class CostCalculator
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecipeRepository $recipeRepo,
        private IngredientPriceRepository $priceRepo,
        private ConfigBoutiqueRepository $configRepo,
    ) {}

    private function r2(float $n): float { return round($n, 2); }
    private function clampPct(float $n): float { return max(0.0, min(100.0, $n)); }

    /** Dédoublonne et range une liste de codes allergènes dans l'ordre réglementaire. */
    private function orderAllergens(array $codes): array
    {
        $ref = array_keys(\App\Twig\BoutiqueExtension::ALLERGENES);
        return array_values(array_intersect($ref, array_unique($codes)));
    }

    /**
     * Statut de conversion entre deux unités :
     *   'ok'     conversion exacte
     *   'approx' conversion approximative (densité = 1 pour litre↔kg)
     *   'none'   conversion impossible (ex. pièce → kg)
     */
    private function conversionStatus(string $from, string $to): string
    {
        if ($from === $to)                        return 'ok';
        if ($from === 'g'     && $to === 'kg')    return 'ok';
        if ($from === 'kg'    && $to === 'g')     return 'ok';
        if ($from === 'litre' && $to === 'kg')    return 'approx';
        if ($from === 'kg'    && $to === 'litre') return 'approx';
        return 'none';
    }

    /** Facteur de conversion entre deux unités. Null si impossible. */
    private function unitFactor(string $from, string $to): ?float
    {
        switch ($this->conversionStatus($from, $to)) {
            case 'ok':
            case 'approx':
                if ($from === $to)                        return 1.0;
                if ($from === 'g'     && $to === 'kg')    return 0.001;
                if ($from === 'kg'    && $to === 'g')     return 1000.0;
                if ($from === 'litre' && $to === 'kg')    return 1.0; // densité = 1 (approx)
                if ($from === 'kg'    && $to === 'litre') return 1.0;
                return 1.0;
            default:
                return null;
        }
    }

    /**
     * Convertit une quantité. Retourne 0.0 si la conversion est impossible
     * (le coût de la ligne sera alors 0 et une alerte sera levée).
     */
    private function convertQty(float $qty, string $from, string $to): float
    {
        $f = $this->unitFactor($from, $to);
        return $f !== null ? $qty * $f : 0.0;
    }

    /** Dernier prix connu pour un ingrédient (optionnellement à une date) */
    private function getPrice(int $ingredientId, ?string $atDate = null): ?array
    {
        $conn = $this->em->getConnection();
        if ($atDate) {
            $row = $conn->fetchAssociative(
                'SELECT price_ht, supplier, effective_date FROM ingredient_prices
                 WHERE ingredient_id = ? AND effective_date <= ?
                 ORDER BY effective_date DESC, id DESC LIMIT 1',
                [$ingredientId, $atDate]
            );
            if ($row) return $row;
        }
        return $conn->fetchAssociative(
            'SELECT price_ht, supplier, effective_date FROM ingredient_prices
             WHERE ingredient_id = ? ORDER BY effective_date DESC, id DESC LIMIT 1',
            [$ingredientId]
        ) ?: null;
    }

    /**
     * Calcule le coût complet d'une recette.
     * @return array{recipe, lines, totals}|null
     */
    public function compute(int $recipeId, ?string $atDate = null, array &$visited = []): ?array
    {
        if (in_array($recipeId, $visited)) {
            throw new \RuntimeException("Cycle détecté : la recette $recipeId se référence elle-même");
        }
        $visited[] = $recipeId;

        $recipe = $this->recipeRepo->find($recipeId);
        if (!$recipe) return null;

        $taux = $this->configRepo->getConfig()->getTauxHoraireMo();

        $materialCost  = 0.0;
        $computedLines = [];

        $allergens = [];
        $traces    = [];

        // Cumul pour le contrôle de cohérence masse (recettes au poids uniquement)
        $netInputKg        = 0.0;
        $allLinesWeighable = true;

        foreach ($recipe->getLines() as $line) {
            $loss  = $this->clampPct($line->getLossPercent());
            $yield = $this->clampPct($line->getYieldPercent());

            $lineData = [
                'line_id'       => $line->getId(),
                'qty_brute'     => $line->getQtyBrute(),
                'unit'          => $line->getUnit(),
                'loss_percent'  => $line->getLossPercent(),
                'yield_percent' => $line->getYieldPercent(),
                'note'          => $line->getNote(),
                'qty_net'       => $this->r2($line->getQtyBrute() * (1 - $loss / 100) * ($yield / 100)),
                'warnings'      => [],
            ];

            $lineCost = 0.0;

            if ($line->getIngredient()) {
                $ing      = $line->getIngredient();
                $priceRow = $this->getPrice($ing->getId(), $atDate);
                $price    = $priceRow ? (float) $priceRow['price_ht'] : 0.0;

                $status   = $this->conversionStatus($line->getUnit(), $ing->getBaseUnit());
                $lineCost = $this->convertQty($line->getQtyBrute(), $line->getUnit(), $ing->getBaseUnit()) * $price;

                $allergens = array_merge($allergens, $ing->getAllergens());
                $traces    = array_merge($traces, $ing->getTraces());

                // Alertes
                if (!$priceRow)            $lineData['warnings'][] = 'no_price';
                if ($status === 'none')    $lineData['warnings'][] = 'unit_mismatch';
                if ($status === 'approx')  $lineData['warnings'][] = 'approx_density';

                // Contrôle de cohérence masse
                $kgStatus = $this->conversionStatus($line->getUnit(), 'kg');
                if ($kgStatus === 'none') {
                    $allLinesWeighable = false;
                } else {
                    $netInputKg += $this->convertQty($line->getQtyBrute(), $line->getUnit(), 'kg')
                                 * (1 - $loss / 100) * ($yield / 100);
                }

                $lineData += [
                    'type'                   => 'ingredient',
                    'ingredient'             => [
                        'id'        => $ing->getId(),
                        'name'      => $ing->getName(),
                        'base_unit' => $ing->getBaseUnit(),
                    ],
                    'price_ht_per_base_unit' => $this->r2($price),
                    'price_meta'             => $priceRow ? [
                        'supplier'       => $priceRow['supplier'],
                        'effective_date' => $priceRow['effective_date'],
                    ] : null,
                ];

            } elseif ($line->getSubRecipe()) {
                $allLinesWeighable = false; // une sous-recette n'entre pas dans le contrôle masse

                $subVisited  = $visited;
                $subComputed = $this->compute($line->getSubRecipe()->getId(), $atDate, $subVisited);
                if (!$subComputed) {
                    throw new \RuntimeException("Sous-recette {$line->getSubRecipe()->getId()} introuvable");
                }

                $subCostPerUnit = $subComputed['totals']['cost_per_output_ht'];
                $subUnit        = $subComputed['recipe']->getOutputType() === 'weight' ? 'kg' : 'portion';

                $allergens = array_merge($allergens, $subComputed['totals']['allergens']);
                $traces    = array_merge($traces, $subComputed['totals']['traces']);

                $qtyInSubUnit = $line->getQtyBrute();
                if ($line->getUnit() !== $subUnit && in_array($line->getUnit(), ['kg','g']) && $subUnit === 'kg') {
                    $qtyInSubUnit = $this->convertQty($line->getQtyBrute(), $line->getUnit(), 'kg');
                }
                $lineCost = $qtyInSubUnit * $subCostPerUnit;

                // Propage les alertes de la sous-recette
                if (($subComputed['totals']['warnings']['total'] ?? 0) > 0) {
                    $lineData['warnings'][] = 'sub_recipe_warning';
                }

                $lineData += [
                    'type'       => 'sub_recipe',
                    'sub_recipe' => [
                        'id'            => $line->getSubRecipe()->getId(),
                        'name'          => $line->getSubRecipe()->getName(),
                        'output_type'   => $line->getSubRecipe()->getOutputType(),
                        'cost_per_unit' => $subCostPerUnit,
                    ],
                    'price_ht_per_base_unit' => $this->r2($subCostPerUnit),
                    'price_meta'             => null,
                ];
            }

            $lineData['line_cost_ht'] = $this->r2($lineCost);
            $materialCost += $lineCost;
            $computedLines[] = $lineData;
        }

        $materialCost = $this->r2($materialCost);

        // Main d'œuvre recalculée en direct (minutes × taux horaire courant)
        $labor     = $this->r2($recipe->getLaborMinutes() / 60 * $taux);
        $packaging = $recipe->getPackagingCostHt();
        $totalCost = $this->r2($materialCost + $labor + $packaging);

        $outputTarget    = $recipe->getOutputValue();
        $outputEffective = $outputTarget
            * (1 - $this->clampPct($recipe->getLossPercent()) / 100)
            * ($this->clampPct($recipe->getYieldPercent()) / 100);
        $denom            = $outputEffective > 0 ? $outputEffective : 1;
        $costPerOutput    = $this->r2($totalCost / $denom);
        $materialPerOut   = $this->r2($materialCost / $denom);

        // ── Prix de vente conseillé ──────────────────────────────────────────
        $pricingValue  = $recipe->getPricingValue();
        $advisedSellHt = 0.0;
        $pricingWarn   = null;

        if ($recipe->getPricingMode() === 'coef') {
            $advisedSellHt = $this->r2($costPerOutput * $pricingValue);
        } else {
            // Mode "marque" : la valeur saisie est un taux de marque (part du PV)
            $m = $pricingValue / 100;
            if ((1 - $m) <= 0) {
                $advisedSellHt = 0.0;
                $pricingWarn   = 'markup_over_100';
            } else {
                $advisedSellHt = $this->r2($costPerOutput / (1 - $m));
            }
        }

        $vat            = $recipe->getProductVatRate() / 100;
        $advisedSellTtc = $this->r2($advisedSellHt * (1 + $vat));

        $marginHt = $this->r2($advisedSellHt - $costPerOutput);

        // Trois écritures de la même marge :
        //  - marque  (sur PV)   = (PV − coût) / PV
        //  - marge   (sur coût) = (PV − coût) / coût
        //  - coefficient        = PV / coût
        $markPercent   = $advisedSellHt > 0 ? $this->r2(($marginHt / $advisedSellHt) * 100) : 0.0; // marque
        $marginPercent = $costPerOutput  > 0 ? $this->r2(($marginHt / $costPerOutput)  * 100) : 0.0; // marge sur coût
        $coefficient   = $costPerOutput  > 0 ? round($advisedSellHt / $costPerOutput, 3) : 0.0;

        // Ratio matière (food cost) = coût matière / PV HT
        $materialRatio = $advisedSellHt > 0 ? $this->r2(($materialPerOut / $advisedSellHt) * 100) : 0.0;

        // ── Synthèse des alertes ─────────────────────────────────────────────
        $wNoPrice = 0; $wUnit = 0; $wApprox = 0;
        foreach ($computedLines as $cl) {
            foreach ($cl['warnings'] as $w) {
                if ($w === 'no_price')       $wNoPrice++;
                if ($w === 'unit_mismatch')  $wUnit++;
                if ($w === 'approx_density') $wApprox++;
            }
        }
        // Contrôle de cohérence : la sortie déclarée ne peut pas dépasser la masse nette entrante
        $outputExceedsInput = false;
        if ($recipe->getOutputType() === 'weight' && $allLinesWeighable && $netInputKg > 0) {
            if ($outputEffective > $netInputKg * 1.001) {
                $outputExceedsInput = true;
            }
        }

        $warnings = [
            'no_price'             => $wNoPrice,
            'unit_mismatch'        => $wUnit,
            'approx_density'       => $wApprox,
            'pricing'              => $pricingWarn,
            'output_exceeds_input' => $outputExceedsInput,
            'net_input_kg'         => $this->r2($netInputKg),
            'total'                => $wNoPrice + $wUnit + ($pricingWarn ? 1 : 0) + ($outputExceedsInput ? 1 : 0),
        ];

        // Allergènes : union ordonnée ; une trace déjà présente n'est plus listée en trace.
        $allergens = $this->orderAllergens($allergens);
        $traces    = $this->orderAllergens(array_diff($traces, $allergens));

        return [
            'recipe' => $recipe,
            'lines'  => $computedLines,
            'totals' => [
                'material_cost_ht'        => $materialCost,
                'labor_cost_ht'           => $labor,
                'packaging_cost_ht'       => $this->r2($packaging),
                'total_cost_ht'           => $totalCost,
                'output_target'           => $this->r2($outputTarget),
                'output_effective'        => $this->r2($outputEffective),
                'cost_per_output_ht'      => $costPerOutput,
                'material_per_output_ht'  => $materialPerOut,
                'advised_sell_ht'         => $advisedSellHt,
                'advised_sell_ttc'        => $advisedSellTtc,
                'margin_ht'               => $marginHt,
                'markup_percent'          => $markPercent,    // taux de marque (sur PV)
                'margin_percent'          => $markPercent,    // alias rétro-compat (= marque, comme avant)
                'margin_on_cost_percent'  => $marginPercent,  // taux de marge (sur coût)
                'coefficient'             => $coefficient,    // coefficient multiplicateur
                'material_ratio_percent'  => $materialRatio,  // ratio matière (food cost)
                'pricing_mode'            => $recipe->getPricingMode(),
                'warnings'                => $warnings,
                'allergens'               => $allergens,
                'traces'                  => $traces,
            ],
        ];
    }

    public function updateCache(int $recipeId): void
    {
        $computed = $this->compute($recipeId);
        if (!$computed) return;

        $recipe = $computed['recipe'];
        $t      = $computed['totals'];

        // Resynchronise le coût MO stocké avec le taux horaire courant
        $recipe->setLaborCostHt($t['labor_cost_ht']);

        $cache = $recipe->getCostCache();
        if (!$cache) {
            $cache = new RecipeCostCache();
            $cache->setRecipe($recipe);
            $recipe->setCostCache($cache);
            $this->em->persist($cache);
        }

        $cache->setComputedAt(new \DateTimeImmutable());
        $cache->setMaterialCostHt($t['material_cost_ht']);
        $cache->setTotalCostHt($t['total_cost_ht']);
        $cache->setCostPerOutputHt($t['cost_per_output_ht']);
        $cache->setAdvisedSellHt($t['advised_sell_ht']);
        $cache->setAdvisedSellTtc($t['advised_sell_ttc']);
        $cache->setMarginHt($t['margin_ht']);
        $cache->setMarginPercent($t['markup_percent']); // marque (inchangé vs avant)

        $this->em->flush();
    }

    public function recalculateAll(?int $ingredientId = null): int
    {
        $conn = $this->em->getConnection();

        $ids = $ingredientId
            ? array_column($conn->fetchAllAssociative(
                'SELECT DISTINCT recipe_id FROM recipe_lines WHERE ingredient_id = ?',
                [$ingredientId]
              ), 'recipe_id')
            : array_column($conn->fetchAllAssociative('SELECT id FROM recipes'), 'id');

        $count = 0;
        foreach ($ids as $id) {
            try { $this->updateCache((int) $id); $count++; }
            catch (\Throwable) { /* cycle ou données incohérentes */ }
        }
        return $count;
    }

    public function wouldCreateCycle(int $parentRecipeId, int $subRecipeId): bool
    {
        if ($parentRecipeId === $subRecipeId) return true;

        $conn    = $this->em->getConnection();
        $visited = [];

        $check = function (int $currentId) use ($parentRecipeId, $conn, &$visited, &$check): bool {
            if (in_array($currentId, $visited)) return false;
            $visited[] = $currentId;
            if ($currentId === $parentRecipeId) return true;

            foreach ($conn->fetchAllAssociative(
                'SELECT sub_recipe_id FROM recipe_lines WHERE recipe_id = ? AND sub_recipe_id IS NOT NULL',
                [$currentId]
            ) as $row) {
                if ($check((int) $row['sub_recipe_id'])) return true;
            }
            return false;
        };

        return $check($subRecipeId);
    }
}

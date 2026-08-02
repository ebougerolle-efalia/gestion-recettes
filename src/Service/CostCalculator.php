<?php
namespace App\Service;

use App\Entity\RecipeCostCache;
use App\Repository\RecipeRepository;
use App\Repository\IngredientPriceRepository;
use App\Repository\ConfigBoutiqueRepository;
use App\Repository\CiqualFoodRepository;
use Doctrine\ORM\EntityManagerInterface;

class CostCalculator
{
    /** Les 8 valeurs nutritionnelles suivies (7 réglementaires, énergie en 2 unités). */
    private const NUT_KEYS = ['energie_kj','energie_kcal','proteines','glucides','lipides','sucres','ag_satures','sel'];

    public function __construct(
        private EntityManagerInterface $em,
        private RecipeRepository $recipeRepo,
        private IngredientPriceRepository $priceRepo,
        private ConfigBoutiqueRepository $configRepo,
        private CiqualFoodRepository $ciqualRepo,
        private UnitConverter $units,
    ) {}

    private function r2(float $n): float { return round($n, 2); }

    /** Les quantités se comptent au gramme : 0,022 kg de sel arrondi à 0,02 fausserait la pesée. */
    private function r3(float $n): float { return round($n, 3); }
    private function clampPct(float $n): float { return max(0.0, min(100.0, $n)); }

    /** Dédoublonne et range une liste de codes allergènes dans l'ordre réglementaire. */
    private function orderAllergens(array $codes): array
    {
        $ref = array_keys(\App\Twig\BoutiqueExtension::ALLERGENES);
        return array_values(array_intersect($ref, array_unique($codes)));
    }

    // Les conversions d'unités vivent dans UnitConverter, partagé avec la
    // réception des factures. Ces trois méthodes ne sont plus que des relais.

    private function conversionStatus(string $from, string $to, ?float $unitW = null): string
    {
        return $this->units->status($from, $to, $unitW);
    }

    private function unitFactor(string $from, string $to, ?float $unitW = null): ?float
    {
        return $this->units->factor($from, $to, $unitW);
    }

    private function convertQty(float $qty, string $from, string $to, ?float $unitW = null): float
    {
        return $this->units->convertQty($qty, $from, $to, $unitW);
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

        // Cumul nutritionnel : totaux absolus par nutriment (sur la base des grammes nets),
        // ramenés "pour 100 g" en fin de calcul. Source des valeurs : table Ciqual.
        $nutNum            = array_fill_keys(self::NUT_KEYS, 0.0);
        $nutMissing        = 0;      // lignes non prises en compte (pas de Ciqual / non pesable)
        $nutCovered        = false;  // au moins une ligne a contribué
        $nutIncompleteSub  = false;  // une sous-recette au calcul incomplet a été utilisée

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
                $unitW    = $ing->getUnitWeightG(); // g/pièce (null si non renseigné)
                $priceRow = $this->getPrice($ing->getId(), $atDate);
                $price    = $priceRow ? (float) $priceRow['price_ht'] : 0.0;

                $status   = $this->conversionStatus($line->getUnit(), $ing->getBaseUnit(), $unitW);
                $lineCost = $this->convertQty($line->getQtyBrute(), $line->getUnit(), $ing->getBaseUnit(), $unitW) * $price;

                $allergens = array_merge($allergens, $ing->getAllergens());
                $traces    = array_merge($traces, $ing->getTraces());

                // Alertes
                if (!$priceRow)            $lineData['warnings'][] = 'no_price';
                if ($status === 'none')    $lineData['warnings'][] = 'unit_mismatch';
                if ($status === 'approx')  $lineData['warnings'][] = 'approx_density';

                // Contrôle de cohérence masse + base nutritionnelle (grammes nets)
                $kgStatus = $this->conversionStatus($line->getUnit(), 'kg', $unitW);
                if ($kgStatus === 'none') {
                    $allLinesWeighable = false;
                    $nutMissing++; // non pesable (ex. pièce sans poids unitaire) → exclu du calcul nutritionnel
                } else {
                    $lineNetKg = $this->convertQty($line->getQtyBrute(), $line->getUnit(), 'kg', $unitW)
                        * (1 - $loss / 100) * ($yield / 100);
                    $netInputKg += $lineNetKg;

                    $food = $ing->getCiqualCode() ? $this->ciqualRepo->find($ing->getCiqualCode()) : null;
                    if ($food) {
                        $vals  = $food->toArray();
                        $netG  = $lineNetKg * 1000;
                        foreach (self::NUT_KEYS as $k) {
                            if ($vals[$k] !== null) {
                                $nutNum[$k] += $vals[$k] * $netG / 100;
                            }
                        }
                        $nutCovered = true;
                    } else {
                        $nutMissing++; // masse présente mais pas de correspondance Ciqual → sous-estimation
                    }
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

                // Apport nutritionnel de la sous-recette (valorisée au poids uniquement)
                $subNut = $subComputed['totals']['nutrition'] ?? null;
                if ($subUnit === 'kg' && $subNut && ($subNut['available'] ?? false)) {
                    $subNetKg = $qtyInSubUnit * (1 - $loss / 100) * ($yield / 100);
                    $subNetG  = $subNetKg * 1000;
                    foreach (self::NUT_KEYS as $k) {
                        $nutNum[$k] += (float) ($subNut['per_100g'][$k] ?? 0) * $subNetG / 100;
                    }
                    $nutCovered = true;
                    if (!($subNut['complete'] ?? false)) {
                        $nutIncompleteSub = true;
                    }
                } else {
                    $nutMissing++; // sous-recette en portions ou sans données → exclue
                }

                // Propage les alertes de la sous-recette
                if (($subComputed['totals']['warnings']['total'] ?? 0) > 0) {
                    $lineData['warnings'][] = 'sub_recipe_warning';
                }

                // Part de la sous-recette réellement consommée ici. Une farce
                // fabriquée par bâches de 10 kg dont on prélève 2,6 kg donne
                // 0,26 : c'est ce coefficient qui rend les quantités et les
                // coûts des composants comparables au reste de la fiche.
                $subOutputValue = (float) $subComputed['recipe']->getOutputValue();
                $subRatio       = $subOutputValue > 0 ? $qtyInSubUnit / $subOutputValue : 0.0;

                $subComponents = [];
                foreach ($subComputed['lines'] as $sl) {
                    $subComponents[] = [
                        'type'          => $sl['type'],
                        'name'          => $sl['type'] === 'ingredient' ? ($sl['ingredient']['name'] ?? '') : ($sl['sub_recipe']['name'] ?? ''),
                        'unit'          => $sl['unit'] ?? '',
                        'loss_percent'  => $sl['loss_percent'] ?? 0,
                        'yield_percent' => $sl['yield_percent'] ?? 0,
                        'note'          => $sl['note'] ?? null,

                        // Quantités de la sous-recette entière — sa propre fiche.
                        'qty_brute'     => $sl['qty_brute'] ?? null,
                        'qty_net'       => $sl['qty_net'] ?? null,
                        'line_cost_ht'  => $sl['line_cost_ht'] ?? 0,

                        // Quantités ramenées à ce qui est consommé DANS cette
                        // recette. Ce sont celles que les fiches affichent :
                        // lire 5 kg de gorge quand il en faut 1,3 conduit tout
                        // droit à peser la bâche entière, et la somme des
                        // composants ne correspondait pas au coût de la ligne.
                        'qty_brute_used'    => $this->r3(($sl['qty_brute'] ?? 0) * $subRatio),
                        'qty_net_used'      => $this->r3(($sl['qty_net'] ?? 0) * $subRatio),
                        'line_cost_ht_used' => $this->r2(($sl['line_cost_ht'] ?? 0) * $subRatio),
                    ];
                }

                $lineData += [
                    'type'       => 'sub_recipe',
                    'sub_recipe' => [
                        'id'            => $line->getSubRecipe()->getId(),
                        'name'          => $line->getSubRecipe()->getName(),
                        'output_type'   => $line->getSubRecipe()->getOutputType(),
                        'output_value'  => $subComputed['recipe']->getOutputValue(),
                        'output_unit'   => $subUnit,
                        'qty_used'      => $this->r2($qtyInSubUnit),
                        'cost_per_unit' => $subCostPerUnit,
                        'components'    => $subComponents,
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

        // ── Prix réellement pratiqué ─────────────────────────────────────────
        // Le conseillé dit ce qu'il faudrait vendre ; le pratiqué dit ce qui est
        // vendu. Seul le second donne une marge opposable.
        $realSellTtc = $recipe->getSellPriceTtc();
        $realSellHt  = null;
        $realMargin  = null;
        $realMarkup  = null;
        $priceGap    = null;

        if ($realSellTtc !== null) {
            $realSellHt = $this->r2($realSellTtc / (1 + $vat));
            $realMargin = $this->r2($realSellHt - $costPerOutput);
            $realMarkup = $realSellHt > 0 ? $this->r2(($realMargin / $realSellHt) * 100) : null;
            $priceGap   = $advisedSellTtc > 0 ? $this->r2((($realSellTtc - $advisedSellTtc) / $advisedSellTtc) * 100) : null;
        }

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

        // Nutrition « pour 100 g » : totaux absolus / poids fini, recettes au poids uniquement.
        $isWeight    = $recipe->getOutputType() === 'weight';
        $finishedG   = $isWeight ? $outputEffective * 1000 : 0.0;
        $nutAvailable = $isWeight && $finishedG > 0 && $nutCovered;
        $per100 = [];
        if ($nutAvailable) {
            foreach (self::NUT_KEYS as $k) {
                $v = $nutNum[$k] / $finishedG * 100;
                // énergie à l'entier ; sel à 0,01 g ; autres macronutriments à 0,1 g
                $per100[$k] = str_starts_with($k, 'energie')
                    ? round($v)
                    : round($v, $k === 'sel' ? 2 : 1);
            }
        }
        $nutrition = [
            'available'         => $nutAvailable,
            'complete'          => $nutAvailable && $nutMissing === 0 && !$nutIncompleteSub,
            'reason'            => !$isWeight ? 'not_weight' : (!$nutCovered ? 'no_data' : null),
            'missing'           => $nutMissing,
            'finished_weight_g' => $isWeight ? $this->r2($finishedG) : null,
            'per_100g'          => $per100,
        ];

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
                'real_sell_ttc'           => $realSellTtc,   // prix pratiqué saisi
                'real_sell_ht'            => $realSellHt,
                'real_margin_ht'          => $realMargin,
                'real_markup_percent'     => $realMarkup,    // marque réelle
                'price_gap_percent'       => $priceGap,      // pratiqué vs conseillé
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
                'nutrition'               => $nutrition,
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

        if ($ingredientId) {
            $ids = array_column($conn->fetchAllAssociative(
                'SELECT DISTINCT recipe_id FROM recipe_lines WHERE ingredient_id = ?',
                [$ingredientId]
            ), 'recipe_id');
            // Le cache des recettes parentes doit suivre : une terrine qui
            // contient une farce voit son coût changer sans qu'aucune de ses
            // lignes ne cite l'ingrédient dont le prix a bougé.
            $ids = $this->withParentRecipes(array_map('intval', $ids));
        } else {
            $ids = array_column($conn->fetchAllAssociative('SELECT id FROM recipes'), 'id');
        }

        $count = 0;
        foreach ($ids as $id) {
            try { $this->updateCache((int) $id); $count++; }
            catch (\Throwable) { /* cycle ou données incohérentes */ }
        }
        return $count;
    }

    /**
     * Recettes dont le coût a le plus dérivé sur une période, en comparant le
     * coût actuel au coût recalculé avec les prix en vigueur à la date de début.
     *
     * Aucun historique de coût n'est stocké : le calcul rejoue simplement les
     * prix datés. C'est ce que permet l'alimentation automatique de la
     * mercuriale — sans factures qui entrent seules, cette page resterait vide.
     *
     * Seules les recettes touchées par un changement de prix sur la période sont
     * recalculées : sur un catalogue de plusieurs centaines de fiches, en
     * recalculer deux fois la totalité à chaque affichage serait intenable.
     *
     * Renvoie des valeurs scalaires et non des entités : le résultat est mis en
     * cache par l'appelant, et mettre des entités Doctrine en cache donnerait
     * des objets détachés au prochain chargement.
     *
     * @return array<int,array{recipe_id:int,recipe_name:string,output_unit:string,cost_now:float,cost_then:float,delta:float,delta_percent:float}>
     */
    public function costDrift(int $days = 30, int $limit = 5, int $maxRecipes = 60): array
    {
        $conn  = $this->em->getConnection();
        $since = (new \DateTimeImmutable())->modify("-$days days")->format('Y-m-d');

        $ingredientIds = array_column($conn->fetchAllAssociative(
            'SELECT DISTINCT ingredient_id FROM ingredient_prices WHERE effective_date > ?',
            [$since]
        ), 'ingredient_id');

        if (!$ingredientIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ingredientIds), '?'));
        $recipeIds = array_map('intval', array_column($conn->fetchAllAssociative(
            "SELECT DISTINCT recipe_id FROM recipe_lines WHERE ingredient_id IN ($placeholders)",
            $ingredientIds
        ), 'recipe_id'));

        // Une terrine dérive quand sa farce dérive, sans citer l'ingrédient.
        $recipeIds = array_slice($this->withParentRecipes($recipeIds), 0, $maxRecipes);

        $drifts = [];
        foreach ($recipeIds as $recipeId) {
            try {
                $now  = $this->compute($recipeId);
                $then = $this->compute($recipeId, $since);
            } catch (\Throwable) {
                continue; // cycle ou données incohérentes : on n'en fait pas une erreur d'affichage
            }
            if (!$now || !$then) {
                continue;
            }

            $costNow  = $now['totals']['cost_per_output_ht'];
            $costThen = $then['totals']['cost_per_output_ht'];

            // Une hausse seulement : une baisse n'appelle aucune décision urgente.
            if ($costThen <= 0 || $costNow <= $costThen) {
                continue;
            }

            $drifts[] = [
                'recipe_id'     => $recipeId,
                'recipe_name'   => $now['recipe']->getName(),
                'output_unit'   => $now['recipe']->getOutputUnitLabel(),
                'cost_now'      => $costNow,
                'cost_then'     => $costThen,
                'delta'         => $this->r2($costNow - $costThen),
                'delta_percent' => $this->r2((($costNow - $costThen) / $costThen) * 100),
            ];
        }

        usort($drifts, fn ($a, $b) => $b['delta_percent'] <=> $a['delta_percent']);

        return array_slice($drifts, 0, $limit);
    }

    /**
     * Complète une liste de recettes par toutes celles qui les utilisent comme
     * sous-recette, de façon transitive (farce → terrine → plateau).
     *
     * @param int[] $ids
     * @return int[]
     */
    private function withParentRecipes(array $ids): array
    {
        $conn  = $this->em->getConnection();
        $known = array_fill_keys($ids, true);
        $queue = $ids;

        while ($queue) {
            $placeholders = implode(',', array_fill(0, count($queue), '?'));
            $parents = array_column($conn->fetchAllAssociative(
                "SELECT DISTINCT recipe_id FROM recipe_lines WHERE sub_recipe_id IN ($placeholders)",
                $queue
            ), 'recipe_id');

            $queue = [];
            foreach ($parents as $parentId) {
                $parentId = (int) $parentId;
                if (!isset($known[$parentId])) {   // coupe aussi les cycles éventuels
                    $known[$parentId] = true;
                    $queue[] = $parentId;
                }
            }
        }

        return array_keys($known);
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
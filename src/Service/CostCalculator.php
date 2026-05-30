<?php
namespace App\Service;

use App\Entity\RecipeCostCache;
use App\Repository\RecipeRepository;
use App\Repository\IngredientPriceRepository;
use Doctrine\ORM\EntityManagerInterface;

class CostCalculator
{
    public function __construct(
        private EntityManagerInterface $em,
        private RecipeRepository $recipeRepo,
        private IngredientPriceRepository $priceRepo,
    ) {}

    private function r2(float $n): float { return round($n, 2); }

    /**
     * Facteur de conversion entre deux unités.
     * Retourne null si la conversion n'est pas gérée.
     */
    private function unitFactor(string $from, string $to): ?float
    {
        if ($from === $to)                        return 1.0;
        if ($from === 'g'     && $to === 'kg')    return 0.001;
        if ($from === 'kg'    && $to === 'g')     return 1000.0;
        if ($from === 'litre' && $to === 'kg')    return 1.0;   // approximation eau/liquides
        if ($from === 'kg'    && $to === 'litre') return 1.0;
        return null;
    }

    /**
     * Convertit une quantité entre deux unités.
     * Si la conversion est impossible (ex: piece → kg), retourne 0.0
     * pour éviter tout crash — le coût de la ligne sera 0 tant que
     * l'ingrédient n'a pas une unité compatible avec celle de la recette.
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

        $materialCost  = 0.0;
        $computedLines = [];

        foreach ($recipe->getLines() as $line) {
            $lineData = [
                'line_id'       => $line->getId(),
                'qty_brute'     => $line->getQtyBrute(),
                'unit'          => $line->getUnit(),
                'loss_percent'  => $line->getLossPercent(),
                'yield_percent' => $line->getYieldPercent(),
                'note'          => $line->getNote(),
                'qty_net'       => $this->r2(
                    $line->getQtyBrute()
                    * (1 - $line->getLossPercent() / 100)
                    * ($line->getYieldPercent() / 100)
                ),
            ];

            $lineCost = 0.0;

            if ($line->getIngredient()) {
                $ing      = $line->getIngredient();
                $priceRow = $this->getPrice($ing->getId(), $atDate);
                $price    = $priceRow ? (float) $priceRow['price_ht'] : 0.0;
                $lineCost = $this->convertQty($line->getQtyBrute(), $line->getUnit(), $ing->getBaseUnit()) * $price;

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
                $subVisited  = $visited;
                $subComputed = $this->compute($line->getSubRecipe()->getId(), $atDate, $subVisited);
                if (!$subComputed) {
                    throw new \RuntimeException("Sous-recette {$line->getSubRecipe()->getId()} introuvable");
                }

                $subCostPerUnit = $subComputed['totals']['cost_per_output_ht'];
                $subUnit        = $subComputed['recipe']->getOutputType() === 'weight' ? 'kg' : 'portion';

                $qtyInSubUnit = $line->getQtyBrute();
                if ($line->getUnit() !== $subUnit && in_array($line->getUnit(), ['kg','g']) && $subUnit === 'kg') {
                    $qtyInSubUnit = $this->convertQty($line->getQtyBrute(), $line->getUnit(), 'kg');
                }
                $lineCost = $qtyInSubUnit * $subCostPerUnit;

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
        $labor        = $recipe->getLaborCostHt();
        $packaging    = $recipe->getPackagingCostHt();
        $totalCost    = $this->r2($materialCost + $labor + $packaging);

        $outputTarget    = $recipe->getOutputValue();
        $outputEffective = $outputTarget
            * (1 - $recipe->getLossPercent() / 100)
            * ($recipe->getYieldPercent() / 100);
        $denom         = $outputEffective > 0 ? $outputEffective : 1;
        $costPerOutput = $this->r2($totalCost / $denom);

        $advisedSellHt = 0.0;
        if ($recipe->getPricingMode() === 'coef') {
            $advisedSellHt = $this->r2($costPerOutput * $recipe->getPricingValue());
        } else {
            $m             = $recipe->getPricingValue() / 100;
            $advisedSellHt = (1 - $m) <= 0 ? 0 : $this->r2($costPerOutput / (1 - $m));
        }

        $vat            = $recipe->getProductVatRate() / 100;
        $advisedSellTtc = $this->r2($advisedSellHt * (1 + $vat));
        $marginHt       = $this->r2($advisedSellHt - $costPerOutput);
        $marginPercent  = $advisedSellHt <= 0 ? 0 : $this->r2(($marginHt / $advisedSellHt) * 100);

        return [
            'recipe' => $recipe,
            'lines'  => $computedLines,
            'totals' => [
                'material_cost_ht'   => $materialCost,
                'labor_cost_ht'      => $this->r2($labor),
                'packaging_cost_ht'  => $this->r2($packaging),
                'total_cost_ht'      => $totalCost,
                'output_target'      => $this->r2($outputTarget),
                'output_effective'   => $this->r2($outputEffective),
                'cost_per_output_ht' => $costPerOutput,
                'advised_sell_ht'    => $advisedSellHt,
                'advised_sell_ttc'   => $advisedSellTtc,
                'margin_ht'          => $marginHt,
                'margin_percent'     => $marginPercent,
            ],
        ];
    }

    public function updateCache(int $recipeId): void
    {
        $computed = $this->compute($recipeId);
        if (!$computed) return;

        $recipe = $computed['recipe'];
        $t      = $computed['totals'];

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
        $cache->setMarginPercent($t['margin_percent']);

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

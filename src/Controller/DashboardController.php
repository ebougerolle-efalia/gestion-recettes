<?php
namespace App\Controller;

use App\Entity\Recipe;
use App\Repository\{PurchaseInvoiceRepository, RecipeRepository};
use App\Service\CostCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tableau de bord : ce qui appelle une décision aujourd'hui.
 *
 * Séparé de l'administration : ce n'en est pas. Un éditeur doit pouvoir le
 * consulter — c'est sa page d'accueil — sans détenir les droits d'administrer
 * les catégories, les familles ou les comptes utilisateurs.
 *
 * Reste réservé aux éditeurs : il porte des montants et des marges, que le
 * profil lecteur ne doit jamais voir.
 */
#[IsGranted('ROLE_EDITOR')]
class DashboardController extends AbstractController
{
    #[Route('/tableau-de-bord', name: 'app_dashboard')]
    public function index(
        EntityManagerInterface $em,
        RecipeRepository $recipeRepo,
        PurchaseInvoiceRepository $invoiceRepo,
        CostCalculator $calc,
        CacheInterface $cache,
    ): Response {
        $conn = $em->getConnection();

        // ── Argent : ce qui se pilote ────────────────────────────────────────
        $recipes = $recipeRepo->findBy([], ['name' => 'ASC']);

        $underpriced = array_filter($recipes, fn (Recipe $r) => $r->isUnderpriced());
        usort($underpriced, fn (Recipe $a, Recipe $b) => $a->getPriceGapPercent() <=> $b->getPriceGapPercent());

        $priced  = array_filter($recipes, fn (Recipe $r) => $r->getRealMarkupPercent() !== null);
        $markups = array_map(fn (Recipe $r) => $r->getRealMarkupPercent(), $priced);

        // Dérive des coûts sur 30 jours : l'indicateur que seule l'alimentation
        // automatique des prix rend possible.
        //
        // Chaque recette concernée est calculée deux fois, aujourd'hui et à la
        // date de départ : deux secondes sur un catalogue de démonstration, bien
        // plus sur plusieurs centaines de fiches. Le résultat est donc mis en
        // cache, avec une clé qui contient le dernier identifiant de prix connu :
        // toute facture validée renouvelle la clé et rafraîchit l'affichage, sans
        // qu'aucune invalidation explicite ne soit nécessaire.
        $lastPriceId = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM ingredient_prices');
        $costDrift   = $cache->get(
            sprintf('dashboard.cost_drift.30.%d.%s', $lastPriceId, date('Y-m-d')),
            function (ItemInterface $item) use ($calc) {
                $item->expiresAfter(3600);
                return $calc->costDrift(30, 5);
            }
        );

        // ── KPIs principaux ──────────────────────────────────────────────────
        $totalRecipes       = (int) $conn->fetchOne('SELECT COUNT(*) FROM recipes');
        $recipesWithCost    = (int) $conn->fetchOne('SELECT COUNT(*) FROM recipe_cost_cache');
        $recipesNoCost      = $totalRecipes - $recipesWithCost;
        $totalIngredients   = (int) $conn->fetchOne('SELECT COUNT(*) FROM ingredients');
        $ingsWithPrice      = (int) $conn->fetchOne('SELECT COUNT(DISTINCT ingredient_id) FROM ingredient_prices');
        $ingsWithoutPrice   = $totalIngredients - $ingsWithPrice;
        $avgMargin          = (float) ($conn->fetchOne(
            'SELECT ROUND(AVG(margin_percent), 1) FROM recipe_cost_cache WHERE margin_percent > 0'
        ) ?: 0);

        // ── Alertes : ingrédients sans aucun prix ────────────────────────────
        $ingsNoPrice = $conn->fetchAllAssociative('
            SELECT i.id, i.name, ic.name AS category
            FROM ingredients i
            LEFT JOIN ingredient_categories ic ON ic.id = i.category_id
            LEFT JOIN ingredient_prices ip     ON ip.ingredient_id = i.id
            WHERE ip.id IS NULL
            ORDER BY i.name
        ');

        // ── Alertes : recettes sans lignes (vides) ───────────────────────────
        $recipesEmpty = $conn->fetchAllAssociative('
            SELECT r.id, r.name, r.family
            FROM recipes r
            LEFT JOIN recipe_lines rl ON rl.recipe_id = r.id
            WHERE rl.id IS NULL
            ORDER BY r.name
        ');

        // ── Alertes : prix anciens (> 90 jours) ──────────────────────────────
        // La soustraction de deux dates donne un nombre de jours entier sous
        // PostgreSQL. L'ancienne version utilisait julianday(), propre à SQLite,
        // et référençait l'alias days_old dans le HAVING — que PostgreSQL
        // n'accepte pas : l'expression doit y être répétée.
        $oldPrices = $conn->fetchAllAssociative('
            SELECT i.id, i.name, MAX(ip.effective_date) AS last_date,
                   (CURRENT_DATE - MAX(ip.effective_date)) AS days_old
            FROM ingredients i
            JOIN ingredient_prices ip ON ip.ingredient_id = i.id
            GROUP BY i.id, i.name
            HAVING (CURRENT_DATE - MAX(ip.effective_date)) > 90
            ORDER BY days_old DESC
            LIMIT 10
        ');

        // ── Ingrédients en hausse sur 30 jours ───────────────────────────────
        // Remplace les anciennes cartes « ingrédients les plus chers » et
        // « recettes les plus coûteuses » : un ingrédient cher n'est pas un
        // problème, un ingrédient qui grimpe en est un.
        // DISTINCT ON est propre à PostgreSQL — assumé, le projet n'en supporte
        // plus d'autre.
        $ingredientRises = $conn->fetchAllAssociative("
            WITH actuel AS (
                SELECT DISTINCT ON (ingredient_id) ingredient_id, price_ht
                FROM ingredient_prices
                ORDER BY ingredient_id, effective_date DESC, id DESC
            ),
            precedent AS (
                SELECT DISTINCT ON (ingredient_id) ingredient_id, price_ht
                FROM ingredient_prices
                WHERE effective_date <= CURRENT_DATE - INTERVAL '30 days'
                ORDER BY ingredient_id, effective_date DESC, id DESC
            )
            SELECT i.id, i.name, i.base_unit,
                   p.price_ht AS old_price, a.price_ht AS new_price,
                   ROUND((a.price_ht - p.price_ht) / p.price_ht * 100, 1) AS delta
            FROM actuel a
            JOIN precedent p    ON p.ingredient_id = a.ingredient_id
            JOIN ingredients i  ON i.id = a.ingredient_id
            WHERE p.price_ht > 0 AND a.price_ht > p.price_ht
            ORDER BY delta DESC
            LIMIT 6
        ");

        // Libellés fournisseurs mémorisés par le rapprochement de factures
        try {
            $mappingCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM invoice_ingredient_mappings');
        } catch (\Throwable) {
            $mappingCount = 0;
        }

        return $this->render('admin/index.html.twig', [
            'kpis' => [
                'total_recipes'     => $totalRecipes,
                'recipes_with_cost' => $recipesWithCost,
                'recipes_no_cost'   => $recipesNoCost,
                'total_ingredients' => $totalIngredients,
                'ings_with_price'   => $ingsWithPrice,
                'ings_no_price'     => $ingsWithoutPrice,
                'avg_margin'        => $avgMargin,
                'priced_count'      => count($priced),
                'median_markup'     => $this->median($markups),
                'pending_invoices'  => $invoiceRepo->countPending(),
            ],
            'underpriced' => array_values($underpriced),
            'cost_drift'  => $costDrift,
            'alerts' => [
                'no_price'  => $ingsNoPrice,
                'empty'     => $recipesEmpty,
                'old_prices'=> $oldPrices,
            ],
            'ingredient_rises' => $ingredientRises,
            'mapping_count'    => $mappingCount,
        ]);
    }

    /**
     * Médiane et non moyenne : sans volumes de vente, une moyenne se laisse
     * tirer par un produit d'appel confidentiel et ne décrit plus rien.
     *
     * @param float[] $values
     */
    private function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }

        sort($values);
        $count  = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : round(($values[$middle - 1] + $values[$middle]) / 2, 1);
    }

}

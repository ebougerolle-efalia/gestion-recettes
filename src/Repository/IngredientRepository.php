<?php
namespace App\Repository;

use App\Entity\Ingredient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * Mercuriale : tous les ingrédients avec leur dernier prix connu, son
     * fournisseur, sa date, et l'évolution sur la période demandée.
     *
     * Les ingrédients sans prix figurent aussi — c'est justement ce qu'on veut
     * voir avant un rendez-vous fournisseur. D'où les jointures externes.
     *
     * DISTINCT ON est propre à PostgreSQL, seul moteur supporté.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findMercuriale(int $days = 30): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative("
            WITH actuel AS (
                SELECT DISTINCT ON (ingredient_id)
                       ingredient_id, price_ht, supplier, effective_date
                FROM ingredient_prices
                ORDER BY ingredient_id, effective_date DESC, id DESC
            ),
            precedent AS (
                SELECT DISTINCT ON (ingredient_id) ingredient_id, price_ht
                FROM ingredient_prices
                WHERE effective_date <= CURRENT_DATE - make_interval(days => :days)
                ORDER BY ingredient_id, effective_date DESC, id DESC
            )
            SELECT i.id, i.name, i.base_unit, i.vat_rate,
                   COALESCE(c.name, 'Sans catégorie') AS category,
                   a.price_ht, a.supplier, a.effective_date,
                   p.price_ht AS previous_price,
                   CASE WHEN p.price_ht > 0
                        THEN ROUND((a.price_ht - p.price_ht) / p.price_ht * 100, 1)
                   END AS delta
            FROM ingredients i
            LEFT JOIN ingredient_categories c ON c.id = i.category_id
            LEFT JOIN actuel a    ON a.ingredient_id = i.id
            LEFT JOIN precedent p ON p.ingredient_id = i.id
            ORDER BY COALESCE(c.sort_order, 999), category, i.name
        ", ['days' => $days]);
    }
}

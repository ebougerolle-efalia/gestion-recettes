<?php
namespace App\Repository;

use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SupplierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Supplier::class);
    }

    /**
     * Trouve un fournisseur par SIRET (identifiant unique fiable).
     * Si pas de SIRET, cherche par nom exact.
     */
    public function findByIdentifier(string $name, ?string $siret): ?Supplier
    {
        if ($siret) {
            $clean = preg_replace('/\s/', '', $siret);
            $found = $this->findOneBy(['siret' => $clean]);
            if ($found) return $found;
        }
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Tous les fournisseurs avec leurs indicateurs agrégés.
     * Retourne un tableau de tableaux associatifs.
     */
    public function findAllWithStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative("
            SELECT
                s.id,
                s.name,
                s.siret,
                s.city,
                s.country,
                s.last_invoice_date,
                COUNT(DISTINCT pi.id)          AS invoice_count,
                COUNT(DISTINCT pil.ingredient_id) AS ingredient_count,
                ROUND(SUM(CASE WHEN pi.total_ht IS NOT NULL THEN pi.total_ht ELSE 0 END), 2) AS total_ht_all,
                ROUND(SUM(CASE
                    WHEN pi.invoice_date >= date('now', '-12 months')
                    THEN pi.total_ht ELSE 0 END), 2) AS total_ht_12m,
                MAX(pi.invoice_date)           AS last_invoice_date_computed
            FROM suppliers s
            LEFT JOIN purchase_invoices pi  ON pi.supplier_id = s.id
            LEFT JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
            GROUP BY s.id, s.name, s.siret, s.city, s.country, s.last_invoice_date
            ORDER BY last_invoice_date_computed DESC NULLS LAST, s.name
        ");
    }

    /**
     * Fiche fournisseur complète : factures + lignes produits avec évolution des prix.
     */
    public function findDetailStats(int $supplierId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Historique des factures
        $invoices = $conn->fetchAllAssociative("
            SELECT id, invoice_id, invoice_date, total_ht, total_ttc, imported_at
            FROM purchase_invoices
            WHERE supplier_id = ?
            ORDER BY invoice_date DESC
        ", [$supplierId]);

        // Produits achetés avec dernier prix et variation
        $products = $conn->fetchAllAssociative("
            SELECT
                i.id AS ingredient_id,
                i.name AS ingredient_name,
                i.base_unit,
                COUNT(pil.id)           AS purchase_count,
                MIN(pil.price_ht)       AS price_min,
                MAX(pil.price_ht)       AS price_max,
                -- Dernier prix
                (SELECT pil2.price_ht FROM purchase_invoice_lines pil2
                 JOIN purchase_invoices pi2 ON pi2.id = pil2.invoice_id
                 WHERE pil2.ingredient_id = i.id AND pi2.supplier_id = ?
                 ORDER BY pi2.invoice_date DESC, pil2.id DESC LIMIT 1) AS last_price,
                -- Prix précédent (pour calcul variation)
                (SELECT pil2.price_ht FROM purchase_invoice_lines pil2
                 JOIN purchase_invoices pi2 ON pi2.id = pil2.invoice_id
                 WHERE pil2.ingredient_id = i.id AND pi2.supplier_id = ?
                 ORDER BY pi2.invoice_date DESC, pil2.id DESC LIMIT 1 OFFSET 1) AS prev_price,
                MAX(pi.invoice_date)    AS last_purchase_date
            FROM purchase_invoice_lines pil
            JOIN purchase_invoices pi ON pi.id = pil.invoice_id
            JOIN ingredients i ON i.id = pil.ingredient_id
            WHERE pi.supplier_id = ? AND pil.ingredient_id IS NOT NULL
            GROUP BY i.id, i.name, i.base_unit
            ORDER BY last_purchase_date DESC, i.name
        ", [$supplierId, $supplierId, $supplierId]);

        // Historique des prix par ingrédient (pour sparklines)
        $priceHistory = $conn->fetchAllAssociative("
            SELECT
                i.id AS ingredient_id,
                pi.invoice_date,
                pil.price_ht
            FROM purchase_invoice_lines pil
            JOIN purchase_invoices pi ON pi.id = pil.invoice_id
            JOIN ingredients i ON i.id = pil.ingredient_id
            WHERE pi.supplier_id = ? AND pil.ingredient_id IS NOT NULL
            ORDER BY i.id, pi.invoice_date ASC
        ", [$supplierId]);

        // Grouper l'historique par ingredient_id
        $historyByIngredient = [];
        foreach ($priceHistory as $row) {
            $historyByIngredient[$row['ingredient_id']][] = [
                'date'  => $row['invoice_date'],
                'price' => (float) $row['price_ht'],
            ];
        }

        return [
            'invoices'           => $invoices,
            'products'           => $products,
            'history_by_ingredient' => $historyByIngredient,
        ];
    }
}

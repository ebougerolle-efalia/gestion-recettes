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

    public function findByIdentifier(string $name, ?string $siret): ?Supplier
    {
        if ($siret) {
            $clean = preg_replace('/\s/', '', $siret);
            $found = $this->findOneBy(['siret' => $clean]);
            if ($found) return $found;
        }
        return $this->findOneBy(['name' => $name]);
    }

    public function findAllWithStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->fetchAllAssociative("
            SELECT
                s.id, s.name, s.siret, s.city, s.country, s.last_invoice_date,
                COUNT(DISTINCT pi.id)             AS invoice_count,
                COUNT(DISTINCT pil.ingredient_id) AS ingredient_count,
                ROUND(SUM(COALESCE(pi.total_ht, 0)), 2) AS total_ht_all,
                ROUND(SUM(CASE
                    WHEN pi.invoice_date >= CURRENT_DATE - INTERVAL '12 months'
                    THEN COALESCE(pi.total_ht, 0) ELSE 0 END), 2) AS total_ht_12m,
                MAX(pi.invoice_date) AS last_invoice_date_computed
            FROM suppliers s
            LEFT JOIN purchase_invoices pi   ON pi.supplier_id = s.id
            LEFT JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
            GROUP BY s.id, s.name, s.siret, s.city, s.country, s.last_invoice_date
            ORDER BY last_invoice_date_computed DESC, s.name
        ");
    }

    /**
     * Détail fournisseur — accepte un filtre optionnel par purchase_invoice.id
     *
     * @param int|null $filterInvoiceId  Si renseigné, limite les produits à cette facture
     */
    public function findDetailStats(int $supplierId, ?int $filterInvoiceId = null): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // ── Toutes les factures du fournisseur (toujours complet pour la sidebar) ──
        $invoices = $conn->fetchAllAssociative("
            SELECT id, invoice_id, invoice_date, total_ht, total_ttc, imported_at
            FROM purchase_invoices
            WHERE supplier_id = ?
            ORDER BY invoice_date DESC
        ", [$supplierId]);

        // ── Produits — filtrés ou non ─────────────────────────────────────────
        $invoiceFilter = $filterInvoiceId
            ? "AND pi.id = $filterInvoiceId"
            : '';

        $products = $conn->fetchAllAssociative("
            SELECT
                i.id    AS ingredient_id,
                i.name  AS ingredient_name,
                i.base_unit,
                COUNT(pil.id)     AS purchase_count,
                MIN(pil.price_ht) AS price_min,
                MAX(pil.price_ht) AS price_max,
                -- Dernier prix sur l'ensemble du fournisseur (pour variation)
                (SELECT pil2.price_ht
                 FROM purchase_invoice_lines pil2
                 JOIN purchase_invoices pi2 ON pi2.id = pil2.invoice_id
                 WHERE pil2.ingredient_id = i.id AND pi2.supplier_id = :sid
                 ORDER BY pi2.invoice_date DESC, pil2.id DESC LIMIT 1) AS last_price,
                -- Prix précédent (pour variation)
                (SELECT pil2.price_ht
                 FROM purchase_invoice_lines pil2
                 JOIN purchase_invoices pi2 ON pi2.id = pil2.invoice_id
                 WHERE pil2.ingredient_id = i.id AND pi2.supplier_id = :sid
                 ORDER BY pi2.invoice_date DESC, pil2.id DESC LIMIT 1 OFFSET 1) AS prev_price,
                MAX(pi.invoice_date) AS last_purchase_date
            FROM purchase_invoice_lines pil
            JOIN purchase_invoices pi ON pi.id = pil.invoice_id
            JOIN ingredients i        ON i.id  = pil.ingredient_id
            WHERE pi.supplier_id = :sid
              AND pil.ingredient_id IS NOT NULL
              $invoiceFilter
            GROUP BY i.id, i.name, i.base_unit
            ORDER BY last_purchase_date DESC, i.name
        ", ['sid' => $supplierId]);

        // ── Historique des prix par ingrédient (pour sparklines) ─────────────
        $priceHistory = $conn->fetchAllAssociative("
            SELECT i.id AS ingredient_id, pi.invoice_date, pil.price_ht
            FROM purchase_invoice_lines pil
            JOIN purchase_invoices pi ON pi.id = pil.invoice_id
            JOIN ingredients i        ON i.id  = pil.ingredient_id
            WHERE pi.supplier_id = ? AND pil.ingredient_id IS NOT NULL
            ORDER BY i.id, pi.invoice_date ASC
        ", [$supplierId]);

        $historyByIngredient = [];
        foreach ($priceHistory as $row) {
            $historyByIngredient[$row['ingredient_id']][] = [
                'date'  => $row['invoice_date'],
                'price' => (float) $row['price_ht'],
            ];
        }

        return [
            'invoices'              => $invoices,
            'products'              => $products,
            'history_by_ingredient' => $historyByIngredient,
        ];
    }
}
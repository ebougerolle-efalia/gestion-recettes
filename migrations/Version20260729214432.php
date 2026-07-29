<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * File d'attente des factures d'achat : statut, source, correspondances
 * proposées ligne par ligne, et unicité (fournisseur, numéro de facture).
 *
 * Note : l'index trigramme idx_ciqual_nom_norm_trgm a été retiré du diff généré.
 * Il utilise gin_trgm_ops, que les métadonnées ORM ne connaissent pas ; laissé
 * tel quel, chaque diff proposerait de le supprimer puis de le recréer en
 * B-tree, ce qui annulerait silencieusement la recherche floue Ciqual.
 */
final class Version20260729214432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'File d\'attente des factures d\'achat (statut, source, correspondances)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice_lines ADD match_source VARCHAR(20) DEFAULT \'none\' NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoice_lines ADD match_score INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoice_lines ADD applied BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD status VARCHAR(20) DEFAULT \'pending\' NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD source VARCHAR(20) DEFAULT \'manual\' NOT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD applied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD raw_payload TEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_per_supplier ON purchase_invoices (supplier_id, invoice_id)');

        // Dans l'ancien flux, une facture n'était enregistrée qu'au moment de la
        // confirmation : tout ce qui existe déjà est donc appliqué, et ses lignes
        // ont produit un prix. Sans cela, l'historique remonterait en file
        // d'attente et pourrait être validé une seconde fois.
        $this->addSql("UPDATE purchase_invoices SET status = 'applied', applied_at = imported_at");
        $this->addSql('UPDATE purchase_invoice_lines SET applied = true');
        $this->addSql("UPDATE purchase_invoice_lines SET match_source = 'mapping', match_score = 100 WHERE ingredient_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice_lines DROP match_source');
        $this->addSql('ALTER TABLE purchase_invoice_lines DROP match_score');
        $this->addSql('ALTER TABLE purchase_invoice_lines DROP applied');
        $this->addSql('DROP INDEX uniq_invoice_per_supplier');
        $this->addSql('ALTER TABLE purchase_invoices DROP status');
        $this->addSql('ALTER TABLE purchase_invoices DROP source');
        $this->addSql('ALTER TABLE purchase_invoices DROP applied_at');
        $this->addSql('ALTER TABLE purchase_invoices DROP note');
        $this->addSql('ALTER TABLE purchase_invoices DROP raw_payload');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réception des factures par courriel.
 *
 * Trois conséquences sur le schéma :
 *   - le fournisseur devient facultatif, parce qu'un PDF nu venu d'une adresse
 *     inconnue est une facture réelle qu'on refuse de perdre ;
 *   - le fichier reçu est tracé (empreinte, chemin, nom, taille) et son
 *     empreinte est unique, ce qui rend la relève rejouable sans doublon ;
 *   - l'adresse d'expédition est conservée sur la facture et mémorisable sur le
 *     fournisseur, pour que les factures suivantes se rattachent seules.
 */
final class Version20260731132233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réception par courriel : fournisseur facultatif, pièce jointe conservée, adresse d\'expédition mémorisée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoices ADD payload_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD attachment_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD attachment_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD attachment_mime VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD attachment_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD sender_email VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD mail_subject VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ALTER supplier_id DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_43ED3CC7831E8F35 ON purchase_invoices (payload_hash)');
        $this->addSql('ALTER TABLE suppliers ADD email VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Le retour en arrière échouera s'il reste des factures en quarantaine
        // sans fournisseur : c'est voulu. Les supprimer en silence ferait
        // disparaître des pièces comptables.
        $this->addSql('DROP INDEX UNIQ_43ED3CC7831E8F35');
        $this->addSql('ALTER TABLE purchase_invoices DROP payload_hash');
        $this->addSql('ALTER TABLE purchase_invoices DROP attachment_path');
        $this->addSql('ALTER TABLE purchase_invoices DROP attachment_name');
        $this->addSql('ALTER TABLE purchase_invoices DROP attachment_mime');
        $this->addSql('ALTER TABLE purchase_invoices DROP attachment_size');
        $this->addSql('ALTER TABLE purchase_invoices DROP sender_email');
        $this->addSql('ALTER TABLE purchase_invoices DROP mail_subject');
        $this->addSql('ALTER TABLE purchase_invoices ALTER supplier_id SET NOT NULL');
        $this->addSql('ALTER TABLE suppliers DROP email');
    }
}

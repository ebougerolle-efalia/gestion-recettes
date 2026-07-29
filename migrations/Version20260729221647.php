<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prix de vente réellement pratiqué sur la recette, pour disposer d'une marge
 * réelle et non seulement théorique.
 */
final class Version20260729221647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prix de vente pratiqué sur la recette';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipes ADD sell_price_ttc NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipes DROP sell_price_ttc');
    }
}

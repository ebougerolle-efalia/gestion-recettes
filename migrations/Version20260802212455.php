<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mode opératoire sur la recette.
 *
 * La fiche s'appelait « fiche labo » mais ne portait que des pesées. Un
 * professionnel y cherche d'abord l'ordre des étapes, les temps et les
 * températures.
 *
 * Colonne facultative : aucune recette existante n'est modifiée, et une
 * instance qui ne s'en sert pas ne voit aucune différence.
 */
final class Version20260802212455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mode opératoire : étapes de fabrication sur la recette';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipes ADD process TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Le retour en arrière efface les modes opératoires saisis : ils ne
        // vivent nulle part ailleurs.
        $this->addSql('ALTER TABLE recipes DROP process');
    }
}

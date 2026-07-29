<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * pg_trgm et index GIN sur ciqual_foods.nom_norm.
 *
 * L'appariement Ciqual présélectionne ses candidats par mots. En LIKE '%mot%',
 * PostgreSQL n'a aucun index utilisable et parcourt les 3 500 lignes à chaque
 * requête. Un index GIN trigramme rend ces LIKE indexables, et ouvre en plus
 * similarity() pour rattraper les fautes de frappe et les variantes.
 *
 * Écrit à la main : doctrine:migrations:diff ne génère ni CREATE EXTENSION ni
 * index à opérateur, et les effacerait du schéma s'il les rencontrait.
 */
final class Version20260729200500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recherche floue Ciqual : extension pg_trgm et index GIN sur nom_norm';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Spécifique à PostgreSQL.'
        );

        // Nécessite le paquet postgresql-contrib et un rôle superutilisateur au
        // premier passage. En base managée, l'extension est souvent déjà
        // disponible dans la liste blanche du fournisseur.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ciqual_nom_norm_trgm ON ciqual_foods USING GIN (nom_norm gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Spécifique à PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_ciqual_nom_norm_trgm');
        // L'extension n'est pas supprimée : elle peut servir à d'autres objets.
    }
}

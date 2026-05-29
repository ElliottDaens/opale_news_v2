<?php

declare(strict_types=1);

/*
Version20260527120000

QUOI : Ajoute la colonne `is_featured` (booléen) sur `events` pour le positionnement préférentiel.

COMMENT : `TINYINT(1) NOT NULL DEFAULT 0` + index `idx_events_featured` pour accélérer le tri DB fallback.

OÙ : Migrations Doctrine — appliquée via `doctrine:migrations:migrate`.

POURQUOI : Permettre à l'admin de marquer un événement comme « Incontournable » et de le faire ressortir en tête de liste.
*/

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_featured column on events for preferential positioning (Incontournables)';
    }

    public function up(Schema $schema): void
    {
        $isPg = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $this->addSql(sprintf(
            'ALTER TABLE events ADD is_featured %s NOT NULL DEFAULT %s',
            $isPg ? 'BOOLEAN' : 'TINYINT(1)',
            $isPg ? 'FALSE' : '0',
        ));
        $this->addSql('CREATE INDEX idx_events_featured ON events (is_featured)');
    }

    public function down(Schema $schema): void
    {
        $isPg = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $this->addSql($isPg ? 'DROP INDEX idx_events_featured' : 'DROP INDEX idx_events_featured ON events');
        $this->addSql('ALTER TABLE events DROP is_featured');
    }
}

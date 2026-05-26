<?php

declare(strict_types=1);

/*
Version20260521120000

QUOI : Ajoute la colonne `update_token` (UUID-like 64 car.) sur `events` pour l’édition organisateur par lien signé.

COMMENT : Index unique `uniq_events_update_token` ; nullable pour les lignes existantes.

OÙ : Migrations Doctrine — appliquée via `doctrine:migrations:migrate`.

POURQUOI : Permettre la modification en auto-service tant que l’événement est en attente de modération.
*/

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521120000 extends AbstractMigration
{
    /** Libellé affiché par `doctrine:migrations:status`. */
    public function getDescription(): string
    {
        return 'Add update_token column on events for self-service organizer edits';
    }

    /** Applique la colonne et l’index unique. */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD update_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_events_update_token ON events (update_token)');
    }

    /** Retire la colonne et l’index (rollback). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_events_update_token');
        $this->addSql('ALTER TABLE events DROP update_token');
    }
}

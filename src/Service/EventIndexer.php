<?php

namespace App\Service;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/*
EventIndexer

QUOI : Synchronise un événement approuvé avec Pinecone (vecteur documentaire) et le flag `indexed`.

COMMENT : Appelle `GeminiService` + `PineconeService`, journalise les échecs, laisse la persistance à Doctrine.

OÙ : Invoqué depuis `AdminController` et la restauration corbeille.

POURQUOI : Garantir une seule porte d’entrée pour l’index vectoriel et rester idempotent.
*/

final class EventIndexer
{
    /**
     * Point d’entrée DI : services d’embedding, d’index distant, persistance et log.
     */
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PineconeService $pinecone,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Pousse ou met à jour le vecteur Pinecone pour un événement éligible (visible publiquement et non supprimé).
     *
     * Comment : embedding `TASK_DOCUMENT` sur `getEmbeddingText()`, `upsert` par id, `indexed = true`, `flush`. Erreurs loguées sans remonter.
     */
    public function index(Event $event): void
    {
        if ($event->isDeleted() || !$event->getStatus()->isVisiblePublicly()) {
            return;
        }

        try {
            $vector = $this->gemini->getEmbedding(
                $event->getEmbeddingText(),
                GeminiService::TASK_DOCUMENT,
            );
            $this->pinecone->upsert((string) $event->getId(), $vector);
            $event->setIndexed(true);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Pinecone index failed', [
                'event_id' => $event->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retire l’événement de Pinecone et remet `indexed` à false (révoque la visibilité côté radar).
     *
     * Comment : noop si jamais indexé et sans id ; sinon `deleteById`, flag, `flush`. Échecs réseau logués.
     */
    public function unindex(Event $event): void
    {
        if (!$event->isIndexed() && $event->getId() === null) {
            return;
        }

        try {
            $this->pinecone->deleteById((string) $event->getId());
            $event->setIndexed(false);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Pinecone unindex failed', [
                'event_id' => $event->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Repository;

use App\Entity\Event;
use App\Enum\EventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/*
EventRepository

QUOI : Accès Doctrine aux événements : listes publiques, modération, corbeille, résolution d’IDs pour la recherche vectorielle.

COMMENT : Requêtes DQL ; `findTrashed` gère le filtre `soft_deleted` ; `findByIdsPreservingOrder` réordonne selon Pinecone.

OÙ : Injecté dans les contrôleurs et services métier.

POURQUOI : Encapsuler filtres de statut / suppression et garantir un ordre stable côté API.
*/

/**
 * @extends ServiceEntityRepository<Event>
 */
final class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return Event[]
     */
    public function findPubliclyVisible(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->setParameter('status', EventStatus::Approved)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOnePublicById(int $id): ?Event
    {
        return $this->createQueryBuilder('e')
            ->where('e.id = :id')
            ->andWhere('e.status = :status')
            ->setParameter('id', $id)
            ->setParameter('status', EventStatus::Approved)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Résout un événement par son jeton d'édition organisateur (lien email auto-service).
     */
    public function findOneByUpdateToken(string $token): ?Event
    {
        if ($token === '') {
            return null;
        }

        return $this->createQueryBuilder('e')
            ->where('e.updateToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Événements publiés avec coordonnées GPS (carte d’accueil).
     *
     * @return Event[]
     */
    public function findWithCoordinates(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.latitude IS NOT NULL')
            ->andWhere('e.longitude IS NOT NULL')
            ->setParameter('status', EventStatus::Approved)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Event[]
     */
    public function findForModeration(?EventStatus $status = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $qb->andWhere('LOWER(e.titre) LIKE :q OR LOWER(e.ville) LIKE :q OR LOWER(e.nomOrganisateur) LIKE :q OR LOWER(e.description) LIKE :q')
                ->setParameter('q', '%' . strtolower($search) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function countSubmittedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Event[]
     */
    public function findPendingIndexation(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.indexed = false')
            ->setParameter('status', EventStatus::Approved)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Event[]
     */
    public function findTrashed(EntityManagerInterface $em): array
    {
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('soft_deleted');
        if ($wasEnabled) {
            $filters->disable('soft_deleted');
        }

        try {
            return $this->createQueryBuilder('e')
                ->where('e.deletedAt IS NOT NULL')
                ->orderBy('e.deletedAt', 'DESC')
                ->getQuery()
                ->getResult();
        } finally {
            if ($wasEnabled) {
                $filters->enable('soft_deleted');
            }
        }
    }

    /**
     * @param int[] $ids
     *
     * @return Event[]
     */
    public function findByIdsPreservingOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $events = $this->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->andWhere('e.status = :status')
            ->setParameter('ids', $ids)
            ->setParameter('status', EventStatus::Approved)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($events as $event) {
            $byId[$event->getId()] = $event;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @return Event[]
     */
    public function findAllOrdered(): array
    {
        return $this->findPubliclyVisible();
    }

    /**
     * Liste publique paginée avec tri + filtres facettes (catégories, période, gratuit).
     *
     * Comment : `sort='date'|'relevance'` → tous deux `ORDER BY startDate ASC` (sans recherche, l'ordre
     * métier par défaut est chronologique). Filtres :
     *  - `categories` : `e.categorie IN (…)` si non vide
     *  - `period` ∈ {'today','weekend','month','all'} : fenêtre sur `e.startDate`
     *  - `freeOnly` : `e.prix IS NULL`
     * Pagination via `setFirstResult` / `setMaxResults` (12 par défaut).
     *
     * @param string[] $categories
     *
     * @return Event[]
     */
    public function findByFilters(
        string $sort = 'relevance',
        int $page = 1,
        int $pageSize = 12,
        array $categories = [],
        string $period = 'all',
        bool $freeOnly = false,
    ): array {
        $page = max(1, $page);
        $offset = ($page - 1) * $pageSize;

        $qb = $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->setParameter('status', EventStatus::Approved)
            ->orderBy('e.startDate', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($pageSize);

        $this->applyFacetFilters($qb, $categories, $period, $freeOnly);

        return $qb->getQuery()->getResult();
    }

    /**
     * Applique les filtres facettes communs (categories, period, freeOnly) sur un QueryBuilder.
     */
    private function applyFacetFilters(
        \Doctrine\ORM\QueryBuilder $qb,
        array $categories,
        string $period,
        bool $freeOnly,
    ): void {
        if ($categories !== []) {
            $qb->andWhere('e.categorie IN (:cats)')->setParameter('cats', $categories);
        }

        if ($freeOnly) {
            $qb->andWhere('e.prix IS NULL');
        }

        [$from, $to] = $this->periodWindow($period);
        if ($from !== null && $to !== null) {
            $qb->andWhere('e.startDate <= :periodTo')
                ->andWhere('COALESCE(e.endDate, e.startDate) >= :periodFrom')
                ->setParameter('periodFrom', $from)
                ->setParameter('periodTo', $to);
        }
    }

    /**
     * Convertit un libellé période en fenêtre [from, to] inclusive (ou [null, null] si 'all').
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function periodWindow(string $period): array
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $today = (new \DateTimeImmutable('today', $tz));

        return match ($period) {
            'today' => [$today, $today],
            'weekend' => (function () use ($today) {
                $dow = (int) $today->format('N'); // 1=lundi … 7=dimanche
                $sat = $dow <= 6 ? $today->modify('+' . (6 - $dow) . ' days') : $today;
                $sun = $sat->modify('+1 day');
                return [$sat, $sun];
            })(),
            'month' => [$today, $today->modify('+30 days')],
            default => [null, null],
        };
    }

    public function countPubliclyVisible(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :status')
            ->setParameter('status', EventStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Événements publics d'une catégorie donnée, excluant un id (fallback aux suggestions similaires).
     *
     * @return Event[]
     */
    public function findRelatedByCategory(string $categorie, int $excludeId, int $limit = 3): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.categorie = :cat')
            ->andWhere('e.id != :excluded')
            ->setParameter('status', EventStatus::Approved)
            ->setParameter('cat', $categorie)
            ->setParameter('excluded', $excludeId)
            ->orderBy('e.startDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Événements publics d'une ville (page ville).
     *
     * @return Event[]
     */
    public function findByVille(string $ville, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('LOWER(e.ville) = :ville')
            ->setParameter('status', EventStatus::Approved)
            ->setParameter('ville', mb_strtolower($ville))
            ->orderBy('e.startDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste des villes distinctes parmi les événements publiés (pour sitemap + listing).
     *
     * @return string[]
     */
    public function findDistinctCities(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.ville AS ville')
            ->where('e.status = :status')
            ->setParameter('status', EventStatus::Approved)
            ->orderBy('e.ville', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['ville'], $rows)));
    }

    /**
     * @return Event[]
     */
    public function findExpiredEvents(int $daysGrace = 2): array
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d days', $daysGrace));

        return $this->createQueryBuilder('e')
            ->where('COALESCE(e.endDate, e.startDate) < :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

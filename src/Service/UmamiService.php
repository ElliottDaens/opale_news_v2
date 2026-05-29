<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/*
UmamiService

QUOI : Récupère le nombre de vues par événement depuis la base PostgreSQL d'Umami.

COMMENT : Requête PDO directe sur `umami_db` (même réseau Docker), extraction de l'ID
          depuis l'URL /evenement/{id}/…, résultats mis en cache 5 min.

OÙ : Injecté dans AdminController pour afficher les stats de vues par fiche.

POURQUOI : L'API Umami v3 ne supporte plus type=url sur /metrics — la DB est
           plus fiable et ne nécessite pas d'authentification HTTP intermédiaire.
*/

final class UmamiService
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::UMAMI_WEBSITE_ID')] private readonly string $websiteId,
        #[Autowire(env: 'default::UMAMI_DB_PASSWORD')] private readonly string $dbPassword,
    ) {}

    /**
     * Retourne [eventId => nbVues] pour toutes les fiches /evenement/{id}/.
     * Retourne [] si Umami n'est pas configuré ou si la DB est inaccessible.
     *
     * @return array<int, int>
     */
    public function getEventViewCounts(): array
    {
        if ($this->websiteId === '' || $this->dbPassword === '') {
            return [];
        }

        try {
            return $this->cache->get('umami.event_views', function (ItemInterface $item): array {
                $item->expiresAfter(300);
                return $this->fetchFromDb();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Umami: impossible de lire les vues', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, int> */
    private function fetchFromDb(): array
    {
        $pdo = new \PDO(
            'pgsql:host=umami_db;port=5432;dbname=umami',
            'umami',
            $this->dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
        );

        $stmt = $pdo->prepare("
            SELECT
                REGEXP_REPLACE(url_path, '.*/evenement/([0-9]+)/.*', '\\1')::int AS event_id,
                COUNT(*) AS views
            FROM website_event
            WHERE website_id = :websiteId
              AND event_type = 1
              AND url_path ~ '/evenement/[0-9]+/'
            GROUP BY event_id
        ");

        $stmt->execute(['websiteId' => $this->websiteId]);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['event_id']] = (int) $row['views'];
        }

        return $counts;
    }
}

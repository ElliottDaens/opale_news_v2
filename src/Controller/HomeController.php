<?php

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\GeminiService;
use App\Service\GeoService;
use App\Service\PineconeService;
use App\Service\ScoringService;
use App\Service\TemporalQueryParser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\NotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/*
HomeController

QUOI : Accueil public, fiche événement (`/evenement/{id}/{slug}`), export ICS, signalement et API JSON `/api/search`.

COMMENT : Embeddings Gemini, requêtes Pinecone, parsing temporel, fusion score + distance, classification primary/secondary et conversion score → pourcentage affiché.

OÙ : Contrôleur HTTP reliant `GeminiService`, `PineconeService`, `GeoService`, `TemporalQueryParser` et `EventRepository`.

POURQUOI : Offrir une recherche « Opale News » alignée sur le référentiel événements et les garde-fous métier.
*/

final class HomeController extends AbstractController
{
    private const PRIMARY_MIN_TOP = 0.715;
    private const PRIMARY_MIN_RESULT = 0.700;
    private const PRIMARY_MAX_GAP = 0.04;
    private const PRIMARY_MAX_RESULTS = 3;

    private const SECONDARY_MIN_TOP = 0.700;
    private const SECONDARY_MIN_RESULT = 0.68;
    private const SECONDARY_MAX_RESULTS = 5;

    private const PROXIMITY_BOOST_MAX = 0.06;
    private const PROXIMITY_RADIUS_KM = 50.0;

    private const PAGE_SIZE = 12;

    private const FACET_CATEGORIES = [
        'Musique', 'Sport', 'Culture', 'Brocante', 'Marché',
        'Gastronomie', 'Famille', 'Festival', 'Atelier', 'Conférence', 'Découverte',
    ];

    private const FACET_PERIODS = [
        ['value' => 'today', 'label' => "Aujourd'hui", 'icon' => '☀️'],
        ['value' => 'weekend', 'label' => 'Ce week-end', 'icon' => '🎉'],
        ['value' => 'month', 'label' => '30 prochains jours', 'icon' => '📅'],
    ];

    /**
     * Injection du dépôt événements pour toutes les actions du contrôleur.
     */
    public function __construct(
        private readonly EventRepository $events,
        private readonly ScoringService $scoring,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Affiche la page d’accueil avec la grille d’événements.
     *
     * Comment : passe au Twig la liste `findAllOrdered()` (événements approuvés, ordre métier).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        $firstPage = $this->events->findByFilters('relevance', 1, self::PAGE_SIZE);
        $total = $this->events->countPubliclyVisible();

        return $this->render('home/index.html.twig', [
            'events' => $firstPage,
            'totalEvents' => $total,
            'hasMore' => $total > self::PAGE_SIZE,
            'pageSize' => self::PAGE_SIZE,
            'mapEvents' => $this->buildMapMarkersFromEvents($this->events->findWithCoordinates()),
            'categories' => self::FACET_CATEGORIES,
            'periods' => self::FACET_PERIODS,
        ]);
    }

    /**
     * Affiche la fiche publique d’un événement (carte Leaflet, SEO, partage).
     *
     * Comment : charge uniquement les événements approuvés ; redirige en 301 si le slug URL ne correspond pas au titre.
     */
    #[Route('/evenement/{id}/{slug}', name: 'app_event_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, string $slug): Response
    {
        $event = $this->events->findOnePublicById($id);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        if ($slug !== $event->getSlug()) {
            return $this->redirectToRoute('app_event_show', [
                'id' => $id,
                'slug' => $event->getSlug(),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('home/show.html.twig', [
            'event' => $event,
        ]);
    }

    /**
     * Télécharge un fichier `.ics` pour ajouter l’événement à un agenda.
     */
    #[Route('/evenement/{id}/ics', name: 'app_event_ics', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ics(int $id): Response
    {
        $event = $this->events->findOnePublicById($id);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        $uid = sprintf('event-%d@opale.news', $event->getId());
        $dtStamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $dtStart = $event->getStartDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtEnd = $event->getEndDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $location = sprintf('%s, %s %s', $event->getAdresse(), $event->getCodePostal(), $event->getVille());
        $description = str_replace(["\r\n", "\n", "\r"], '\\n', $event->getDescription());
        $summary = addcslashes($event->getTitre(), ",\\;");

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Opale News//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtStamp,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'LOCATION:' . addcslashes($location, ",\\;"),
            'URL:' . $this->urlGenerator->generate('app_event_show', [
                'id' => $event->getId(),
                'slug' => $event->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            'END:VEVENT',
            'END:VCALENDAR',
        ]) . "\r\n";

        return new Response($ics, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="evenement-%d.ics"', $event->getId()),
        ]);
    }

    /**
     * Signalement d’erreur sur une fiche : alerte email à l’admin puis retour sur la page.
     */
    #[Route('/evenement/{id}/signaler', name: 'app_event_signaler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signaler(int $id, Request $request, NotificationService $notifications): Response
    {
        $event = $this->events->findOnePublicById($id);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('signaler-event-' . $event->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $notifications->sendAdminSignalementAlert($event);

        $this->addFlash('success', 'Merci ! Votre signalement a été transmis à notre équipe.');

        return $this->redirectToRoute('app_event_show', [
            'id' => $event->getId(),
            'slug' => $event->getSlug(),
        ]);
    }

    /**
     * Recherche JSON : liste sans filtre, ou recherche sémantique (+ optionnel geo et filtre date naturel).
     *
     * Comment : si `q` vide → tous les events en `primary`. Sinon : parse temporel (`TemporalQueryParser`),
     * embedding requête (`TASK_QUERY`), `topK` plus large si fenêtre date, `query` Pinecone, `blendWithDistance`,
     * puis `classifyMatches` ou `classifyMatchesWithDate`, hydrate et JSON `{ primary, secondary, temporal?, hasPosition }`.
     * Panne Pinecone → log + fallback DB (`findAllOrdered`). Autre erreur → log + 500 générique (jamais le message brut).
     */
    #[Route('/api/search', name: 'app_api_search', methods: ['GET'])]
    public function search(
        Request $request,
        GeminiService $gemini,
        PineconeService $pinecone,
        GeoService $geo,
        TemporalQueryParser $temporalParser,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));
        $userLat = $request->query->has('lat') ? (float) $request->query->get('lat') : null;
        $userLng = $request->query->has('lng') ? (float) $request->query->get('lng') : null;
        $hasPosition = $userLat !== null && $userLng !== null;

        $sort = $request->query->get('sort') === 'date' ? 'date' : 'relevance';
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rawCategories = (array) $request->query->all('categories');
        $categories = array_values(array_intersect($rawCategories, self::FACET_CATEGORIES));
        $period = in_array(
            $request->query->get('period', 'all'),
            ['today', 'weekend', 'month', 'all'],
            true,
        ) ? (string) $request->query->get('period', 'all') : 'all';
        $freeOnly = $request->query->getBoolean('freeOnly', false);
        $hasFacets = $categories !== [] || $period !== 'all' || $freeOnly;

        if ($query === '') {
            $events = $this->events->findByFilters(
                $sort,
                $page,
                self::PAGE_SIZE,
                $categories,
                $period,
                $freeOnly,
            );
            $data = array_map(
                fn (Event $e): array => $this->buildEventRow($e, null, null, $userLat, $userLng, $geo),
                $events,
            );

            return $this->json([
                'primary' => $data,
                'secondary' => [],
                'map' => $this->buildMapMarkersFromRows($data),
                'hasPosition' => $hasPosition,
                'page' => $page,
                'sort' => $sort,
                'hasMore' => count($data) >= self::PAGE_SIZE,
            ]);
        }

        $temporal = $temporalParser->parse($query);
        $queryForEmbedding = $temporal !== null
            ? $temporalParser->stripPhrase($query, $temporal['phrase'])
            : $query;

        if ($queryForEmbedding === '') {
            $queryForEmbedding = 'sortie événement';
        }

        try {
            $vector = $gemini->getEmbedding($queryForEmbedding, GeminiService::TASK_QUERY);

            $baseTopK = $temporal !== null
                ? 30
                : self::PRIMARY_MAX_RESULTS + self::SECONDARY_MAX_RESULTS;
            $topK = max($baseTopK, $page * self::PAGE_SIZE + self::PAGE_SIZE);
            // Filtres facettes actifs → on quadruple le topK pour absorber le post-filtrage PHP
            // (sinon une catégorie rare pourrait n'avoir aucun résultat dans la fenêtre topK initiale).
            if ($hasFacets) {
                $topK *= 4;
            }

            try {
                $matches = $pinecone->query($vector, $topK);
            } catch (\Throwable $e) {
                $this->logger->error('Pinecone query failed, falling back to database listing', [
                    'exception' => $e,
                    'query' => $query,
                ]);

                $events = $this->events->findByFilters($sort, $page, self::PAGE_SIZE);
                $data = array_map(
                    fn (Event $event): array => $this->buildEventRow($event, null, null, $userLat, $userLng, $geo),
                    $events,
                );

                return $this->json([
                    'primary' => $data,
                    'secondary' => [],
                    'map' => $this->buildMapMarkersFromRows($data),
                    'hasPosition' => $hasPosition,
                    'page' => $page,
                    'sort' => $sort,
                    'hasMore' => count($data) >= self::PAGE_SIZE,
                ]);
            }

            $blended = $this->blendWithDistance($matches, $userLat, $userLng, $geo);

            $classified = $temporal !== null
                ? $this->classifyMatchesWithDate($blended, $temporal)
                : $this->classifyMatches($blended);

            $temporalPayload = null;
            if ($temporal !== null) {
                $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
                $temporalPayload = [
                    'label' => $temporal['label'],
                    'from' => $temporal['from']->format('Y-m-d'),
                    'to' => $temporal['to']->format('Y-m-d'),
                    'fromHuman' => $formatter->format($temporal['from']),
                    'toHuman' => $formatter->format($temporal['to']),
                    'singleDay' => $temporal['from']->format('Y-m-d') === $temporal['to']->format('Y-m-d'),
                ];
            }

            $primaryRows = $this->hydrateMatches($classified['primary'], $userLat, $userLng, $geo);
            $secondaryRows = $this->hydrateMatches($classified['secondary'], $userLat, $userLng, $geo);

            if ($hasFacets) {
                $primaryRows = $this->applyFacetsToRows($primaryRows, $categories, $period, $freeOnly);
                $secondaryRows = $this->applyFacetsToRows($secondaryRows, $categories, $period, $freeOnly);
            }

            if ($sort === 'date') {
                $merged = array_merge($primaryRows, $secondaryRows);
                usort($merged, static function (array $a, array $b): int {
                    return strcmp($a['startDate'] ?? '', $b['startDate'] ?? '');
                });
                $primaryRows = $merged;
                $secondaryRows = [];
            }

            $paginatedPrimary = array_slice($primaryRows, $offset, self::PAGE_SIZE);
            $hasMore = count($paginatedPrimary) >= self::PAGE_SIZE
                && count($primaryRows) > $offset + self::PAGE_SIZE;

            return $this->json([
                'primary' => $paginatedPrimary,
                'secondary' => $page === 1 ? $secondaryRows : [],
                'map' => $this->buildMapMarkersFromRows(array_merge($primaryRows, $secondaryRows)),
                'hasPosition' => $hasPosition,
                'temporal' => $temporalPayload,
                'page' => $page,
                'sort' => $sort,
                'hasMore' => $hasMore,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Search request failed', [
                'exception' => $e,
                'query' => $query,
            ]);

            return $this->json(['error' => 'Service de recherche temporairement indisponible.'], 500);
        }
    }

    /**
     * Répartit les matches en primary (dans la fenêtre calendaire demandée) et secondary (hors fenêtre ou complément).
     *
     * Comment : recharge les events, scinde `inRange` / hors range ; primary = in-range au-dessus du seuil ;
     * secondary = autres matches ≥ 0.60, triés par proximité calendaire (`daysToTemporalTarget`) puis score.
     *
     * @param array<array{id: int, score: float, semanticScore: float, distanceKm: float|null}> $matches
     * @param array{from: \DateTimeImmutable, to: \DateTimeImmutable, phrase: string, label: string} $temporal
     *
     * @return array{primary: array<int, array<string, mixed>>, secondary: array<int, array<string, mixed>>}
     */
    private function classifyMatchesWithDate(array $matches, array $temporal): array
    {
        if ($matches === []) {
            return ['primary' => [], 'secondary' => []];
        }

        $ids = array_map(static fn (array $m): int => $m['id'], $matches);
        $events = $this->events->findByIdsPreservingOrder($ids);
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event->getId()] = $event;
        }

        $inRange = [];
        $outOfRange = [];
        foreach ($matches as $m) {
            $event = $eventsById[$m['id']] ?? null;
            if ($event === null) {
                continue;
            }
            if ($this->eventInTemporalRange($event, $temporal)) {
                $inRange[] = $m;
            } else {
                $outOfRange[] = $m;
            }
        }

        $primary = array_values(array_filter(
            $inRange,
            static fn (array $m): bool => $m['score'] >= self::SECONDARY_MIN_RESULT,
        ));
        $primary = array_slice($primary, 0, self::PRIMARY_MAX_RESULTS);

        $primaryIds = array_column($primary, 'id');
        $secondary = [];
        foreach ($matches as $m) {
            if (in_array($m['id'], $primaryIds, true)) {
                continue;
            }
            if ($m['score'] < 0.60) {
                continue;
            }
            $secondary[] = $m;
        }

        usort($secondary, function (array $a, array $b) use ($eventsById, $temporal): int {
            $eventA = $eventsById[$a['id']] ?? null;
            $eventB = $eventsById[$b['id']] ?? null;
            if ($eventA === null) {
                return 1;
            }
            if ($eventB === null) {
                return -1;
            }

            $distA = $this->daysToTemporalTarget($eventA, $temporal);
            $distB = $this->daysToTemporalTarget($eventB, $temporal);

            if ($distA === $distB) {
                return $b['score'] <=> $a['score'];
            }

            return $distA <=> $distB;
        });

        $secondary = array_slice($secondary, 0, self::SECONDARY_MAX_RESULTS);

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * Indique si l’intervalle de dates de l’événement chevauche la fenêtre temporelle de la requête.
     *
     * Comment : compare `[startDate, endDate|startDate]` à `[temporal.from, temporal.to]` (bornes inclusives).
     */
    private function eventInTemporalRange(Event $event, array $temporal): bool
    {
        $eventStart = $event->getStartDate();
        $eventEnd = $event->getEndDate() ?? $eventStart;

        return $eventStart <= $temporal['to'] && $eventEnd >= $temporal['from'];
    }

    /**
     * Mesure à quel point le début de l’événement est proche de la fenêtre demandée (en jours).
     *
     * Comment : 0 si le début est dans la fenêtre ; sinon nombre de jours jusqu’à la borne la plus proche (before/after).
     */
    private function daysToTemporalTarget(Event $event, array $temporal): int
    {
        $eventStart = $event->getStartDate();

        if ($eventStart >= $temporal['from'] && $eventStart <= $temporal['to']) {
            return 0;
        }

        if ($eventStart < $temporal['from']) {
            return (int) $temporal['from']->diff($eventStart)->days;
        }

        return (int) $eventStart->diff($temporal['to'])->days;
    }

    /**
     * Recalcul chaque score Pinecone en y ajoutant un bonus selon la distance utilisateur ↔ lieu (si coords des deux côtés).
     *
     * Comment : pour chaque match, charge l’event, calcule km via `GeoService::distanceKm`, facteur 0–1 via
     * `proximityFactor`, ajoute `PROXIMITY_BOOST_MAX * facteur` au cosinus ; retrie par `score` décroissant.
     * Conserve `semanticScore` et `distanceKm` pour l’UI.
     *
     * @param array<array{id: int, score: float}> $matches
     *
     * @return array<array{id: int, score: float, semanticScore: float, distanceKm: float|null}>
     */
    private function blendWithDistance(array $matches, ?float $userLat, ?float $userLng, GeoService $geo): array
    {
        if ($matches === []) {
            return [];
        }

        $ids = array_map(static fn (array $m): int => $m['id'], $matches);
        $events = $this->events->findByIdsPreservingOrder($ids);
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event->getId()] = $event;
        }

        $blended = [];
        foreach ($matches as $m) {
            $event = $eventsById[$m['id']] ?? null;
            $semanticScore = $m['score'];
            $distanceKm = null;
            $combined = $semanticScore;

            if ($userLat !== null && $userLng !== null && $event !== null && $event->hasCoordinates()) {
                $distanceKm = $geo->distanceKm(
                    $userLat,
                    $userLng,
                    $event->getLatitude(),
                    $event->getLongitude(),
                );
                $proximity = $geo->proximityFactor($distanceKm, self::PROXIMITY_RADIUS_KM);
                $combined = $semanticScore + self::PROXIMITY_BOOST_MAX * $proximity;
            }

            $blended[] = [
                'id' => $m['id'],
                'score' => $combined,
                'semanticScore' => $semanticScore,
                'distanceKm' => $distanceKm,
            ];
        }

        usort($blended, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $blended;
    }

    /**
     * Sépare les résultats cosinus en « bande principale » (très pertinents) et suggestions (restant acceptables).
     *
     * Comment : si le meilleur score global est sous `SECONDARY_MIN_TOP`, rien. Sinon, primary = matches ≥
     * `max(PRIMARY_MIN_RESULT, top - PRIMARY_MAX_GAP)` si `top ≥ PRIMARY_MIN_TOP`, plafonnés ; secondary =
     * autres au-dessus de `SECONDARY_MIN_RESULT`, limités en nombre.
     *
     * @param array<array{id: int, score: float, semanticScore: float, distanceKm: float|null}> $matches
     *
     * @return array{primary: array<int, array<string, mixed>>, secondary: array<int, array<string, mixed>>}
     */
    private function classifyMatches(array $matches): array
    {
        if ($matches === []) {
            return ['primary' => [], 'secondary' => []];
        }

        $overallTop = $matches[0]['score'];

        if ($overallTop < self::SECONDARY_MIN_TOP) {
            return ['primary' => [], 'secondary' => []];
        }

        $primary = [];
        if ($overallTop >= self::PRIMARY_MIN_TOP) {
            $cutoff = max(
                self::PRIMARY_MIN_RESULT,
                $overallTop - self::PRIMARY_MAX_GAP,
            );
            $primary = array_values(array_filter(
                $matches,
                static fn (array $m): bool => $m['score'] >= $cutoff,
            ));
            $primary = array_slice($primary, 0, self::PRIMARY_MAX_RESULTS);
        }

        $primaryIds = array_column($primary, 'id');
        $secondary = [];
        foreach ($matches as $m) {
            if (in_array($m['id'], $primaryIds, true)) {
                continue;
            }
            if ($m['score'] < self::SECONDARY_MIN_RESULT) {
                continue;
            }
            $secondary[] = $m;
        }
        $secondary = array_slice($secondary, 0, self::SECONDARY_MAX_RESULTS);

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * Transforme une liste de matches (métadonnées score/distance) en lignes JSON prêtes pour le front.
     *
     * Comment : charge les `Event` dans l’ordre des IDs matchés, réapplique `buildEventRow` avec les scores stockés.
     *
     * @param array<array{id: int, score: float, semanticScore: float, distanceKm: float|null}> $matches
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateMatches(array $matches, ?float $userLat, ?float $userLng, GeoService $geo): array
    {
        if ($matches === []) {
            return [];
        }

        $orderedIds = array_map(static fn (array $m): int => $m['id'], $matches);
        $events = $this->events->findByIdsPreservingOrder($orderedIds);

        $metaById = [];
        foreach ($matches as $m) {
            $metaById[$m['id']] = $m;
        }

        $results = [];
        foreach ($events as $event) {
            $meta = $metaById[$event->getId()];
            $row = $this->buildEventRow(
                $event,
                $meta['score'],
                $meta['semanticScore'],
                $userLat,
                $userLng,
                $geo,
                $meta['distanceKm'],
            );
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Construit le tableau JSON d’un événement : champs métier + scores et distance si contexte recherche.
     *
     * Comment : part de `toArray()` ; distance en priorité pré-calculée (`blendWithDistance`), sinon Haversine si user
     * géolocalisé ; si scores présents, ajoute `score`, `semanticScore`, `pertinence` (pourcentage via `scoreToPertinence`).
     *
     * @return array<string, mixed>
     */
    private function buildEventRow(
        Event $event,
        ?float $combinedScore,
        ?float $semanticScore,
        ?float $userLat,
        ?float $userLng,
        GeoService $geo,
        ?float $precomputedDistance = null,
    ): array {
        $row = $event->toArray();
        $row['url'] = $this->urlGenerator->generate('app_event_show', [
            'id' => $event->getId(),
            'slug' => $event->getSlug(),
        ]);

        $distanceKm = $precomputedDistance;
        if ($distanceKm === null && $userLat !== null && $userLng !== null && $event->hasCoordinates()) {
            $distanceKm = $geo->distanceKm(
                $userLat,
                $userLng,
                $event->getLatitude(),
                $event->getLongitude(),
            );
        }

        if ($distanceKm !== null) {
            $row['distanceKm'] = round($distanceKm, 1);
        }

        if ($combinedScore !== null) {
            $row['score'] = round($combinedScore, 4);
            $row['semanticScore'] = round($semanticScore, 4);
            $row['pertinence'] = $this->scoring->scoreToPertinence($combinedScore);
        }

        return $row;
    }

    /**
     * Filtre une liste de lignes JSON événements selon les facettes UI (catégories, période, gratuit).
     *
     * Comment : appliqué après hydratation Pinecone pour respecter le tri sémantique ; les rows dont la
     * catégorie n'est pas dans `categories` (si non vide), dont la fenêtre temporelle ne chevauche pas
     * `period`, ou qui ne sont pas gratuites (si `freeOnly`) sont écartées.
     *
     * @param list<array<string, mixed>> $rows
     * @param string[] $categories
     *
     * @return list<array<string, mixed>>
     */
    private function applyFacetsToRows(array $rows, array $categories, string $period, bool $freeOnly): array
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $today = (new \DateTimeImmutable('today', $tz));

        [$periodFrom, $periodTo] = match ($period) {
            'today' => [$today, $today],
            'weekend' => (function () use ($today) {
                $dow = (int) $today->format('N');
                $sat = $dow <= 6 ? $today->modify('+' . (6 - $dow) . ' days') : $today;
                return [$sat, $sat->modify('+1 day')];
            })(),
            'month' => [$today, $today->modify('+30 days')],
            default => [null, null],
        };

        return array_values(array_filter($rows, static function (array $row) use ($categories, $periodFrom, $periodTo, $freeOnly, $tz): bool {
            if ($categories !== [] && !in_array($row['categorie'] ?? null, $categories, true)) {
                return false;
            }

            if ($freeOnly && !empty($row['prix']) && $row['prix'] !== 'Gratuit') {
                return false;
            }

            if ($periodFrom !== null && $periodTo !== null) {
                $startStr = $row['startDate'] ?? null;
                if (!is_string($startStr) || $startStr === '') {
                    return false;
                }
                $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startStr, $tz);
                if ($start === false) {
                    return false;
                }
                $endStr = $row['endDate'] ?? null;
                $end = is_string($endStr) && $endStr !== ''
                    ? (\DateTimeImmutable::createFromFormat('Y-m-d', $endStr, $tz) ?: $start)
                    : $start;
                if ($start > $periodTo || $end < $periodFrom) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Points carte d’accueil à partir d’entités Event.
     *
     * @param Event[] $events
     *
     * @return list<array<string, mixed>>
     */
    private function buildMapMarkersFromEvents(array $events): array
    {
        $markers = [];
        foreach ($events as $event) {
            if (!$event->hasCoordinates()) {
                continue;
            }
            $markers[] = [
                'id' => $event->getId(),
                'slug' => $event->getSlug(),
                'titre' => $event->getTitre(),
                'categorie' => $event->getCategorie(),
                'date' => $event->formatDateRange(),
                'ville' => $event->getVille(),
                'latitude' => $event->getLatitude(),
                'longitude' => $event->getLongitude(),
                'url' => $this->urlGenerator->generate('app_event_show', [
                    'id' => $event->getId(),
                    'slug' => $event->getSlug(),
                ]),
            ];
        }

        return $markers;
    }

    /**
     * Points carte à partir des lignes JSON recherche (dédupliqués par id).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function buildMapMarkersFromRows(array $rows): array
    {
        $markers = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            if (!isset($row['latitude'], $row['longitude']) || $row['latitude'] === null || $row['longitude'] === null) {
                continue;
            }

            $seen[$id] = true;
            $markers[] = [
                'id' => $id,
                'slug' => $row['slug'] ?? 'evenement',
                'titre' => $row['titre'] ?? '',
                'categorie' => $row['categorie'] ?? '',
                'date' => $row['date'] ?? '',
                'ville' => $row['ville'] ?? '',
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'url' => $row['url'] ?? $this->urlGenerator->generate('app_event_show', [
                    'id' => $id,
                    'slug' => $row['slug'] ?? 'evenement',
                ]),
            ];
        }

        return $markers;
    }
}

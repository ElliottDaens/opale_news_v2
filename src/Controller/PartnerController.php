<?php

namespace App\Controller;

use App\Repository\EventRepository;
use App\Service\GeoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/*
PartnerController

QUOI : Flux RSS B2B « Syndication de contenu » pour les partenaires locaux (campings, hébergements…).

COMMENT : Filtre géographique (bounding box SQL + Haversine PHP), fenêtre temporelle semainière,
positionnement préférentiel `is_featured` appliqué par le repository. Renvoie un RSS 2.0 valide
avec URL absolues vers les fiches Opale News.

OÙ : Route publique `/partner/feed`, aucun rôle requis.

POURQUOI : Permettre une intégration "copier-coller" dans n'importe quel CMS via un plugin RSS natif.
*/

final class PartnerController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Page de génération du flux RSS pour les partenaires (campings, hébergements, OT…).
     */
    #[Route('/pour-mon-site', name: 'app_partner_widget', methods: ['GET'])]
    public function widget(): Response
    {
        return $this->render('partner/widget.html.twig');
    }

    /**
     * Flux RSS partenaire géolocalisé.
     *
     * Paramètres GET :
     *   lat     (float, requis)  — latitude du partenaire
     *   lng     (float, requis)  — longitude du partenaire
     *   radius  (float, défaut 20, max 50) — rayon en km
     *   days    (int,   défaut 7, max 15)  — horizon temporel en jours
     *
     * Exemple : /partner/feed?lat=50.52&lng=1.58&radius=15&days=7
     */
    #[Route('/partner/feed', name: 'app_partner_feed', methods: ['GET'])]
    public function feed(Request $request, GeoService $geo): Response
    {
        $lat = $request->query->has('lat') ? (float) $request->query->get('lat') : null;
        $lng = $request->query->has('lng') ? (float) $request->query->get('lng') : null;

        if ($lat === null || $lng === null || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return new Response(
                'Paramètres requis : lat et lng. Exemple : /partner/feed?lat=50.52&lng=1.58&radius=20&days=7',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $radius = min(50.0, max(1.0, (float) ($request->query->get('radius') ?? 20)));
        $days = min(15, max(1, (int) ($request->query->get('days') ?? 7)));

        $feedEvents = $this->events->findForPartnerFeed($lat, $lng, $radius, $days);

        $items = [];
        foreach ($feedEvents as $event) {
            $items[] = [
                'event' => $event,
                'url' => $this->urlGenerator->generate('app_event_show', [
                    'id' => $event->getId(),
                    'slug' => $event->getSlug(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'distanceKm' => round($geo->distanceKm(
                    $lat, $lng,
                    (float) $event->getLatitude(),
                    (float) $event->getLongitude(),
                ), 1),
            ];
        }

        $feedUrl = $this->urlGenerator->generate('app_partner_feed', [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
            'days' => $days,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $xml = $this->renderView('partner/feed.xml.twig', [
            'items' => $items,
            'feedUrl' => $feedUrl,
            'siteUrl' => $this->urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'generatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')),
            'radius' => $radius,
            'days' => $days,
        ]);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}

<?php

namespace App\Controller;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/*
SystemController

QUOI : Routes transverses non métier — healthcheck monitoring, pages légales (mentions, RGPD), sitemap XML SEO.

COMMENT : `/health` renvoie un JSON minimal ; les pages légales rendent des templates Twig statiques ;
         `/sitemap.xml` génère via `XMLWriter` la home + toutes les fiches événements approuvées.

OÙ : Exposé publiquement, pas d'authentification.

POURQUOI : Pré-requis MVP — supervision infra, conformité légale française, indexation Google Events.
*/

final class SystemController extends AbstractController
{
    private const SITEMAP_CATEGORIES = [
        'Musique', 'Sport', 'Culture', 'Brocante', 'Marché',
        'Gastronomie', 'Famille', 'Festival', 'Atelier', 'Conférence', 'Découverte',
    ];

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'OK']);
    }

    #[Route('/mentions-legales', name: 'app_legal_notice', methods: ['GET'])]
    public function legalNotice(): Response
    {
        return $this->render('legal/mentions-legales.html.twig');
    }

    #[Route('/politique-de-confidentialite', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        return $this->render('legal/politique-de-confidentialite.html.twig');
    }

    /**
     * Sitemap XML pour les crawlers SEO (Google, Bing).
     *
     * Comment : URLs absolues via `UrlGeneratorInterface`, balise `lastmod` basée sur `updatedAt`,
     * priorité dégressive (home 1.0, fiches 0.7). Cache HTTP côté navigateur/CDN d'1 heure.
     */
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(EventRepository $events, UrlGeneratorInterface $urlGenerator): Response
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $homeUrl = $urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $writer->startElement('url');
        $writer->writeElement('loc', $homeUrl);
        $writer->writeElement('changefreq', 'daily');
        $writer->writeElement('priority', '1.0');
        $writer->endElement();

        $slugger = new AsciiSlugger('fr');

        foreach (self::SITEMAP_CATEGORIES as $category) {
            $slug = strtolower((string) $slugger->slug($category));
            $writer->startElement('url');
            $writer->writeElement('loc', $urlGenerator->generate('app_category_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL));
            $writer->writeElement('changefreq', 'daily');
            $writer->writeElement('priority', '0.8');
            $writer->endElement();
        }

        foreach ($events->findDistinctCities() as $city) {
            $slug = strtolower((string) $slugger->slug($city));
            if ($slug === '') {
                continue;
            }
            $writer->startElement('url');
            $writer->writeElement('loc', $urlGenerator->generate('app_city_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL));
            $writer->writeElement('changefreq', 'daily');
            $writer->writeElement('priority', '0.8');
            $writer->endElement();
        }

        foreach ($events->findPubliclyVisible() as $event) {
            $url = $urlGenerator->generate('app_event_show', [
                'id' => $event->getId(),
                'slug' => $event->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $writer->startElement('url');
            $writer->writeElement('loc', $url);
            $writer->writeElement('lastmod', $event->getUpdatedAt()->format('Y-m-d'));
            $writer->writeElement('changefreq', 'weekly');
            $writer->writeElement('priority', '0.7');
            $writer->endElement();
        }

        foreach (['app_legal_notice', 'app_privacy_policy', 'app_event_submit'] as $route) {
            $writer->startElement('url');
            $writer->writeElement('loc', $urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL));
            $writer->writeElement('changefreq', 'monthly');
            $writer->writeElement('priority', '0.3');
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return new Response($writer->outputMemory(), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

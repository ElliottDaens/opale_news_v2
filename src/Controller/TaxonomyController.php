<?php

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/*
TaxonomyController

QUOI : Pages publiques d'agrégation par catégorie (`/categorie/{slug}`) et par ville (`/ville/{slug}`).

COMMENT : Résout le slug → libellé via AsciiSlugger sur les valeurs canoniques (catégories de référence,
villes distinctes en base). 404 si non trouvé. Rend une grille d'événements approuvés filtrés.

POURQUOI : Multiplier les pages indexables (SEO longue traîne) et offrir une navigation thématique / locale
naturelle aux visiteurs qui arrivent sur une fiche.
*/

final class TaxonomyController extends AbstractController
{
    private const CATEGORIES = [
        'Musique', 'Sport', 'Culture', 'Brocante', 'Marché',
        'Gastronomie', 'Famille', 'Festival', 'Atelier', 'Conférence', 'Découverte',
    ];

    public function __construct(
        private readonly EventRepository $events,
    ) {}

    #[Route('/categorie/{slug}', name: 'app_category_show', methods: ['GET'])]
    public function category(string $slug): Response
    {
        $slugger = new AsciiSlugger('fr');
        $category = null;

        foreach (self::CATEGORIES as $candidate) {
            if (strtolower((string) $slugger->slug($candidate)) === $slug) {
                $category = $candidate;
                break;
            }
        }

        if ($category === null) {
            throw $this->createNotFoundException('Catégorie inconnue.');
        }

        $events = $this->events->findByFilters('date', 1, 60, [$category]);

        return $this->render('taxonomy/category.html.twig', [
            'category' => $category,
            'events' => $events,
            'totalEvents' => count($events),
        ]);
    }

    #[Route('/ville/{slug}', name: 'app_city_show', methods: ['GET'])]
    public function city(string $slug): Response
    {
        $slugger = new AsciiSlugger('fr');
        $city = null;

        foreach ($this->events->findDistinctCities() as $candidate) {
            if (strtolower((string) $slugger->slug($candidate)) === $slug) {
                $city = $candidate;
                break;
            }
        }

        if ($city === null) {
            throw $this->createNotFoundException('Ville inconnue.');
        }

        $events = $this->events->findByVille($city);

        return $this->render('taxonomy/city.html.twig', [
            'city' => $city,
            'events' => $events,
            'totalEvents' => count($events),
        ]);
    }
}

<?php

namespace App\Controller;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Form\EventSubmissionType;
use App\Repository\EventRepository;
use App\Service\EventIndexer;
use App\Service\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/*
AdminController

QUOI : Espace `ROLE_ADMIN` : tableau de bord, modération, édition de fiche, corbeille, transitions de statut synchronisées avec Pinecone.

COMMENT : CSRF sur les POST, appels à `EventIndexer` pour Pinecone quand la visibilité publique change, toggle du filtre soft-delete pour la corbeille.

OÙ : Préfixe `/admin` ; couplé aux templates Twig `admin/*`.

POURQUOI : Donner un workflow clair d’approbation / rejet / restauration synchronisé avec l’index vectoriel.
*/

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    /**
     * Injection dépôt, persistance, indexeur vectoriel et clé Google Maps pour les formulaires.
     */
    public function __construct(
        private readonly EventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly EventIndexer $indexer,
        #[Autowire(env: 'GMAPS_API_KEY')] private readonly string $gmapsApiKey = '',
    ) {}

    /**
     * Tableau de bord : compteurs par statut, soumissions récentes, file d’attente.
     *
     * Comment : agrège `findForModeration`, `findTrashed`, `countSubmittedSince` sur 7 j / mois courant.
     */
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $now = new \DateTimeImmutable();
        $weekAgo = $now->modify('-7 days');
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);

        $stats = [
            'pending' => count($this->events->findForModeration(EventStatus::Pending)),
            'approved' => count($this->events->findForModeration(EventStatus::Approved)),
            'rejected' => count($this->events->findForModeration(EventStatus::Rejected)),
            'trashed' => count($this->events->findTrashed($this->em)),
            'submittedWeek' => $this->events->countSubmittedSince($weekAgo),
            'submittedMonth' => $this->events->countSubmittedSince($monthStart),
        ];

        $recentPending = array_slice(
            $this->events->findForModeration(EventStatus::Pending),
            0,
            5,
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentPending' => $recentPending,
        ]);
    }

    /**
     * Liste filtrable des événements (pending / approved / rejected / all) avec recherche texte.
     *
     * Comment : query `status` + `q` ; compteurs par onglet pour le template `admin/events`.
     */
    #[Route('/admin/events', name: 'app_admin_events', methods: ['GET'])]
    public function listEvents(Request $request): Response
    {
        $filter = $request->query->get('status', 'pending');
        $status = match ($filter) {
            'approved' => EventStatus::Approved,
            'rejected' => EventStatus::Rejected,
            'pending' => EventStatus::Pending,
            'all' => null,
            default => EventStatus::Pending,
        };
        if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
            $filter = 'pending';
        }

        $search = trim((string) $request->query->get('q', ''));
        $events = $this->events->findForModeration($status, $search !== '' ? $search : null);

        return $this->render('admin/events.html.twig', [
            'events' => $events,
            'currentStatus' => $filter,
            'searchQuery' => $search,
            'counts' => [
                'pending' => count($this->events->findForModeration(EventStatus::Pending)),
                'approved' => count($this->events->findForModeration(EventStatus::Approved)),
                'rejected' => count($this->events->findForModeration(EventStatus::Rejected)),
                'all' => count($this->events->findForModeration()),
            ],
        ]);
    }

    /**
     * Édition admin d’un événement (formulaire partagé avec la soumission publique).
     *
     * Comment : `flush` puis `EventIndexer::index` si déjà publié (`indexed`).
     */
    #[Route('/admin/event/{id}/edit', name: 'app_admin_event_edit', methods: ['GET', 'POST'])]
    public function edit(Event $event, Request $request): Response
    {
        $form = $this->createForm(EventSubmissionType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            if ($event->isIndexed()) {
                $this->indexer->index($event);
            }

            $this->addFlash('success', sprintf('« %s » mis à jour.', $event->getTitre()));

            return $this->redirectToRoute('app_admin_events');
        }

        return $this->render('admin/edit.html.twig', [
            'form' => $form,
            'event' => $event,
            'gmaps_api_key' => $this->gmapsApiKey,
        ]);
    }

    /**
     * Approuve un événement et l’indexe dans Pinecone.
     *
     * Comment : CSRF `approve{id}` ; `Event::approve()` ; flash succès.
     */
    #[Route('/admin/events/{id}/approve', name: 'app_admin_event_approve', methods: ['POST'])]
    public function approve(Event $event, Request $request): Response
    {
        $this->verifyCsrf('approve' . $event->getId(), $request);

        $event->approve();
        $this->em->flush();

        $this->indexer->index($event);

        $this->addFlash('success', sprintf('« %s » approuvé et publié.', $event->getTitre()));

        return $this->redirectBack($request);
    }

    /**
     * Refuse un événement ; désindexe Pinecone s’il était publié.
     *
     * Comment : mémorise l’ancien statut pour `undo` ; `unindex` si était Approved.
     */
    #[Route('/admin/events/{id}/reject', name: 'app_admin_event_reject', methods: ['POST'])]
    public function reject(Event $event, Request $request): Response
    {
        $this->verifyCsrf('reject' . $event->getId(), $request);

        $previousStatus = $event->getStatus();
        $event->reject();
        $this->em->flush();

        if ($previousStatus === EventStatus::Approved) {
            $this->indexer->unindex($event);
        }

        $this->addFlash('warning', sprintf('« %s » refusé.', $event->getTitre()));

        return $this->redirectBack($request);
    }

    /**
     * Annule la dernière décision de modération et resynchronise l’index si besoin.
     *
     * Comment : `undoModeration()` sur l’entité ; index/unindex selon transition de visibilité.
     */
    #[Route('/admin/events/{id}/undo', name: 'app_admin_event_undo', methods: ['POST'])]
    public function undo(Event $event, Request $request): Response
    {
        $this->verifyCsrf('undo' . $event->getId(), $request);

        $statusBefore = $event->getStatus();
        $event->undoModeration();
        $this->em->flush();

        if ($statusBefore === EventStatus::Approved && !$event->getStatus()->isVisiblePublicly()) {
            $this->indexer->unindex($event);
        }
        if ($statusBefore !== EventStatus::Approved && $event->getStatus() === EventStatus::Approved) {
            $this->indexer->index($event);
        }

        $this->addFlash('info', sprintf('Modération de « %s » annulée (statut : %s).', $event->getTitre(), $event->getStatus()->label()));

        return $this->redirectBack($request);
    }

    /**
     * Envoie un événement en corbeille (soft-delete) et le retire du radar si publié.
     */
    #[Route('/admin/events/{id}/delete', name: 'app_admin_event_delete', methods: ['POST'])]
    public function softDelete(Event $event, Request $request): Response
    {
        $this->verifyCsrf('delete' . $event->getId(), $request);

        $event->softDelete();
        $this->em->flush();

        if ($event->getStatus() === EventStatus::Approved) {
            $this->indexer->unindex($event);
        }

        $this->addFlash('info', sprintf('« %s » mis à la corbeille.', $event->getTitre()));

        return $this->redirectBack($request);
    }

    /**
     * Affiche la corbeille (événements soft-deleted).
     */
    #[Route('/admin/trash', name: 'app_admin_trash', methods: ['GET'])]
    public function trash(): Response
    {
        return $this->render('admin/trash.html.twig', [
            'events' => $this->events->findTrashed($this->em),
        ]);
    }

    /**
     * Restaure un événement depuis la corbeille et ré-indexe s’il est approuvé.
     *
     * Comment : désactive temporairement le filtre `soft_deleted` pour charger l’entité.
     */
    #[Route('/admin/trash/{id}/restore', name: 'app_admin_trash_restore', methods: ['POST'])]
    public function restore(int $id, Request $request): Response
    {
        $this->verifyCsrf('restore' . $id, $request);

        $this->em->getFilters()->disable('soft_deleted');
        $event = $this->events->find($id);
        $this->em->getFilters()->enable('soft_deleted');

        if ($event === null) {
            throw $this->createNotFoundException();
        }

        $event->restore();
        $this->em->flush();

        if ($event->getStatus() === EventStatus::Approved) {
            $this->indexer->index($event);
        }

        $this->addFlash('success', sprintf('« %s » restauré.', $event->getTitre()));

        return $this->redirectToRoute('app_admin_trash');
    }

    /**
     * Suppression définitive : Pinecone, fichiers uploadés, ligne SQL.
     */
    #[Route('/admin/trash/{id}/destroy', name: 'app_admin_trash_destroy', methods: ['POST'])]
    public function destroy(int $id, Request $request, ImageUploader $uploader): Response
    {
        $this->verifyCsrf('destroy' . $id, $request);

        $this->em->getFilters()->disable('soft_deleted');
        $event = $this->events->find($id);
        $this->em->getFilters()->enable('soft_deleted');

        if ($event === null) {
            throw $this->createNotFoundException();
        }

        $this->indexer->unindex($event);
        $uploader->delete($event->getImageCouverture());
        $uploader->delete($event->getImageBanniere());

        $titre = $event->getTitre();
        $this->em->remove($event);
        $this->em->flush();

        $this->addFlash('info', sprintf('« %s » supprimé définitivement.', $titre));

        return $this->redirectToRoute('app_admin_trash');
    }

    /**
     * Vide toute la corbeille (purge définitive en boucle).
     */
    #[Route('/admin/trash/empty', name: 'app_admin_trash_empty', methods: ['POST'])]
    public function emptyTrash(Request $request, ImageUploader $uploader): Response
    {
        $this->verifyCsrf('empty_trash', $request);

        $this->em->getFilters()->disable('soft_deleted');
        $trashed = $this->events->findTrashed($this->em);
        $this->em->getFilters()->enable('soft_deleted');

        $count = 0;
        foreach ($trashed as $event) {
            $this->indexer->unindex($event);
            $uploader->delete($event->getImageCouverture());
            $uploader->delete($event->getImageBanniere());
            $this->em->remove($event);
            ++$count;
        }
        $this->em->flush();

        $this->addFlash('info', sprintf('%d événement(s) supprimé(s) définitivement.', $count));

        return $this->redirectToRoute('app_admin_trash');
    }

    /**
     * Vérifie le jeton CSRF d’un formulaire POST admin.
     */
    private function verifyCsrf(string $tokenId, Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }
    }

    /**
     * Redirige vers le referer admin ou la liste modération par défaut.
     */
    private function redirectBack(Request $request): Response
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null && str_contains($referer, '/admin/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_admin_events');
    }
}

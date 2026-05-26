<?php

namespace App\Controller;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Form\EventSubmissionType;
use App\Repository\EventRepository;
use App\Service\GeoService;
use App\Service\ImageUploader;
use App\Service\NotificationService;
use App\Service\RecaptchaVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/*
EventSubmissionController

QUOI : Affiche et traite le formulaire public de proposition d’événement et la page de remerciement.

COMMENT : Rate limit par IP, honeypot, vérification reCAPTCHA v3, upload via `ImageUploader`, statut forcé à `Pending`, pas d’indexation tant que non modéré.

OÙ : Routes `/proposer-un-evenement` pour le grand public.

POURQUOI : Offrir un canal sourcé contrôlé (spam, fichiers) avant modération admin.
*/

final class EventSubmissionController extends AbstractController
{
    /**
     * Clés publiques reCAPTCHA et Google Maps injectées dans le template de soumission.
     */
    public function __construct(
        #[Autowire(env: 'RECAPTCHA_SITE_KEY')] private readonly string $recaptchaSiteKey,
        #[Autowire(env: 'GMAPS_API_KEY')] private readonly string $gmapsApiKey,
    ) {}

    /**
     * Affiche et traite le formulaire de proposition d’événement.
     *
     * Comment : rate limit IP, honeypot `website`, reCAPTCHA v3, uploads, géocodage fallback, statut Pending, emails admin + organisateur.
     */
    #[Route('/proposer-un-evenement', name: 'app_event_submit', methods: ['GET', 'POST'])]
    public function submit(
        Request $request,
        EntityManagerInterface $em,
        ImageUploader $uploader,
        RecaptchaVerifier $recaptcha,
        GeoService $geoService,
        NotificationService $notifications,
        #[Autowire(service: 'limiter.event_submission')] RateLimiterFactory $eventSubmissionLimiter,
    ): Response {
        $limiter = $eventSubmissionLimiter->create($request->getClientIp() ?? 'anon');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Trop de soumissions depuis votre adresse, réessayez plus tard.',
            );
        }

        $event = new Event();
        $form = $this->createForm(EventSubmissionType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('website')->getData() !== null && $form->get('website')->getData() !== '') {
                return $this->redirectToRoute('app_event_submit_thanks');
            }

            $token = (string) $request->request->get('g-recaptcha-response');
            if (!$recaptcha->verify($token, $request->getClientIp())) {
                $this->addFlash('error', 'Vérification anti-spam échouée. Merci de réessayer dans quelques secondes.');

                return $this->render('event/submission.html.twig', [
                    'form' => $form,
                    'recaptcha_site_key' => $this->recaptchaSiteKey,
                    'gmaps_api_key' => $this->gmapsApiKey,
                ]);
            }

            try {
                $cover = $form->get('imageCouverture')->getData();
                if ($cover !== null) {
                    $event->setImageCouverture($uploader->save($cover, 'cover'));
                }

                $banner = $form->get('imageBanniere')->getData();
                if ($banner !== null) {
                    $event->setImageBanniere($uploader->save($banner, 'banner'));
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur upload : ' . $e->getMessage());

                return $this->render('event/submission.html.twig', [
                    'form' => $form,
                    'recaptcha_site_key' => $this->recaptchaSiteKey,
                    'gmaps_api_key' => $this->gmapsApiKey,
                ]);
            }

            if ($event->getLatitude() === null || $event->getLongitude() === null) {
                $fullAddress = trim(sprintf(
                    '%s, %s %s, France',
                    $event->getAdresse(),
                    $event->getCodePostal(),
                    $event->getVille(),
                ));
                $coords = $geoService->geocodeAddress($fullAddress);
                if ($coords !== null) {
                    $event->setLatitude($coords['lat']);
                    $event->setLongitude($coords['lng']);
                }
            }

            $event->setStatus(EventStatus::Pending);
            $event->ensureUpdateToken();

            $em->persist($event);
            $em->flush();

            $notifications->sendAdminNewEventAlert($event);
            $notifications->sendOrganizerConfirmation($event);

            return $this->redirectToRoute('app_event_submit_thanks');
        }

        return $this->render('event/submission.html.twig', [
            'form' => $form,
            'recaptcha_site_key' => $this->recaptchaSiteKey,
            'gmaps_api_key' => $this->gmapsApiKey,
        ]);
    }

    /**
     * Page de remerciement après soumission réussie (sans données sensibles).
     */
    #[Route('/proposer-un-evenement/merci', name: 'app_event_submit_thanks', methods: ['GET'])]
    public function thanks(): Response
    {
        return $this->render('event/submission_thanks.html.twig');
    }

    /**
     * Édition auto-service par l'organisateur via le jeton reçu par email.
     *
     * Comment : verrou de modération — si l'événement a déjà été validé ou refusé par l'admin,
     * le lien renvoie 403 (le contenu modéré est figé). Reprend `EventSubmissionType`, gère
     * uploads et géocodage comme `submit()`, mais ne touche ni au statut ni au jeton ;
     * force `indexed=false` pour que le sidecar cron repousse les changements vers Pinecone.
     */
    #[Route('/event/modifier/{updateToken}', name: 'app_event_edit_by_token', requirements: ['updateToken' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function editByToken(
        string $updateToken,
        Request $request,
        EventRepository $events,
        EntityManagerInterface $em,
        ImageUploader $uploader,
        GeoService $geoService,
    ): Response {
        $event = $events->findOneByUpdateToken($updateToken);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        if ($event->getStatus() !== EventStatus::Pending) {
            // 403 direct (et non createAccessDeniedException) pour éviter la redirection firewall
            // vers /admin/login : le lien est public, pas authentifié, on doit afficher l'erreur en clair.
            throw new HttpException(
                Response::HTTP_FORBIDDEN,
                'Cette proposition a déjà été modérée et ne peut plus être modifiée.',
            );
        }

        $form = $this->createForm(EventSubmissionType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $cover = $form->get('imageCouverture')->getData();
                if ($cover !== null) {
                    $event->setImageCouverture($uploader->save($cover, 'cover'));
                }

                $banner = $form->get('imageBanniere')->getData();
                if ($banner !== null) {
                    $event->setImageBanniere($uploader->save($banner, 'banner'));
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur upload : ' . $e->getMessage());

                return $this->render('event/edit.html.twig', [
                    'form' => $form,
                    'event' => $event,
                    'recaptcha_site_key' => $this->recaptchaSiteKey,
                    'gmaps_api_key' => $this->gmapsApiKey,
                ]);
            }

            if ($event->getLatitude() === null || $event->getLongitude() === null) {
                $fullAddress = trim(sprintf(
                    '%s, %s %s, France',
                    $event->getAdresse(),
                    $event->getCodePostal(),
                    $event->getVille(),
                ));
                $coords = $geoService->geocodeAddress($fullAddress);
                if ($coords !== null) {
                    $event->setLatitude($coords['lat']);
                    $event->setLongitude($coords['lng']);
                }
            }

            $event->setIndexed(false);
            $em->flush();

            $this->addFlash('success', 'Votre proposition a été mise à jour. Elle reste en attente de modération.');

            return $this->redirectToRoute('app_event_edit_by_token', [
                'updateToken' => $updateToken,
            ]);
        }

        return $this->render('event/edit.html.twig', [
            'form' => $form,
            'event' => $event,
            'recaptcha_site_key' => $this->recaptchaSiteKey,
            'gmaps_api_key' => $this->gmapsApiKey,
        ]);
    }
}

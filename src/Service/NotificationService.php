<?php

namespace App\Service;

use App\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/*
NotificationService

QUOI : Centralise l'envoi des emails transactionnels liés au cycle de vie d'un événement.

COMMENT : Encapsule `MailerInterface`, rend les templates Twig dans `templates/emails/` et construit des `Email` typés avec expéditeur et destinataire dérivés de la configuration et de l'entité Event.

OÙ : `EventSubmissionController`, modération admin, signalement public (`sendAdminSignalementAlert`).

POURQUOI : Un seul point d’envoi (templates, adresses, erreurs transport loguées sans bloquer l’utilisateur).
*/

final class NotificationService
{
    /**
     * Mailer, Twig, générateur d’URL et adresses issues de l’environnement.
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')] private readonly string $fromAddress,
        #[Autowire(env: 'MAILER_FROM_NAME')] private readonly string $fromName,
        #[Autowire(env: 'ADMIN_EMAIL')] private readonly string $adminEmail,
    ) {}

    /**
     * Prévient l’admin qu’une nouvelle proposition attend modération.
     *
     * Comment : rend `emails/admin_new_event` (HTML + texte) ; envoi best-effort via `dispatch`.
     */
    public function sendAdminNewEventAlert(Event $event): void
    {
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($this->adminEmail)
            ->subject(sprintf('[Opale News] Nouvelle soumission : %s', $event->getTitre()))
            ->html($this->twig->render('emails/admin_new_event.html.twig', ['event' => $event]))
            ->text($this->twig->render('emails/admin_new_event.txt.twig', ['event' => $event]));

        $this->dispatch($email);
    }

    /**
     * Alerte immédiate à l'admin lorsqu'un visiteur signale une fiche erronée depuis la page publique.
     *
     * Comment : génère un lien absolu vers l'écran de modération (`app_admin_event_edit`) pour que l'admin puisse
     * intervenir en un clic. Le contenu reste minimal : titre, ville, URL publique, URL de modération.
     */
    public function sendAdminSignalementAlert(Event $event): void
    {
        $moderationUrl = $this->urlGenerator->generate(
            'app_admin_event_edit',
            ['id' => $event->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $publicUrl = $this->urlGenerator->generate(
            'app_event_show',
            ['id' => $event->getId(), 'slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $context = [
            'event' => $event,
            'moderationUrl' => $moderationUrl,
            'publicUrl' => $publicUrl,
        ];

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($this->adminEmail)
            ->subject(sprintf('[Opale News] Signalement reçu : %s', $event->getTitre()))
            ->html($this->twig->render('emails/admin_signalement.html.twig', $context))
            ->text($this->twig->render('emails/admin_signalement.txt.twig', $context));

        $this->dispatch($email);
    }

    /**
     * Accusé de réception à l’organisateur avec lien d’édition tokenisé si disponible.
     */
    public function sendOrganizerConfirmation(Event $event): void
    {
        $editUrl = null;
        if ($event->getUpdateToken() !== null) {
            $editUrl = $this->urlGenerator->generate(
                'app_event_edit_by_token',
                ['updateToken' => $event->getUpdateToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        }

        $context = ['event' => $event, 'editUrl' => $editUrl];

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($event->getEmailContact(), $event->getNomOrganisateur()))
            ->subject('Nous avons bien reçu votre proposition d\'événement')
            ->html($this->twig->render('emails/organizer_confirmation.html.twig', $context))
            ->text($this->twig->render('emails/organizer_confirmation.txt.twig', $context));

        $this->dispatch($email);
    }

    /**
     * Informe l’organisateur que son événement est publié (lien fiche publique).
     */
    public function sendOrganizerApprovalNotification(Event $event): void
    {
        $eventUrl = $this->urlGenerator->generate(
            'app_event_show',
            ['id' => $event->getId(), 'slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $context = ['event' => $event, 'eventUrl' => $eventUrl];

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($event->getEmailContact(), $event->getNomOrganisateur()))
            ->subject(sprintf('Votre événement « %s » est publié sur Opale News', $event->getTitre()))
            ->html($this->twig->render('emails/organizer_approval.html.twig', $context))
            ->text($this->twig->render('emails/organizer_approval.txt.twig', $context));

        $this->dispatch($email);
    }

    /**
     * Informe l’organisateur du refus avec le motif saisi par l’admin.
     */
    public function sendOrganizerRejectionNotification(Event $event, string $reason): void
    {
        $context = ['event' => $event, 'reason' => $reason];

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($event->getEmailContact(), $event->getNomOrganisateur()))
            ->subject(sprintf('Votre proposition « %s » n\'a pas été retenue', $event->getTitre()))
            ->html($this->twig->render('emails/organizer_rejection.html.twig', $context))
            ->text($this->twig->render('emails/organizer_rejection.txt.twig', $context));

        $this->dispatch($email);
    }

    /**
     * Envoie l’email ou logue l’échec sans interrompre le flux HTTP.
     */
    private function dispatch(Email $email): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Best-effort : un transport indisponible ne doit pas bloquer la soumission publique,
            // mais on logge explicitement pour pouvoir diagnostiquer en dev comme en prod.
            $this->logger->error('Mailer: échec d\'envoi.', [
                'exception' => $e->getMessage(),
                'to' => array_map(fn ($a) => $a->getAddress(), $email->getTo()),
                'subject' => $email->getSubject(),
            ]);
        }
    }
}

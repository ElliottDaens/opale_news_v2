<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/*
NotFoundExceptionSubscriber

QUOI : Rend la page 404 personnalisée (`error404.html.twig`) pour toute `NotFoundHttpException`, y compris en dev.

COMMENT : Écoute `kernel.exception` avec priorité 250 (avant le listener du profiler à 0) ; si l’exception est un
NotFoundHttpException, rend le template TwigBundle/Exception/error404 et stoppe la propagation pour court-circuiter
la page de debug Symfony.

OÙ : Auto-enregistré par l’autoconfiguration (`config/services.yaml`).

POURQUOI : En production, Symfony rend déjà ce template, mais en dev la page de debug s’interpose. Cet override
permet de prévisualiser la 404 finale sur n’importe quelle URL inexistante, sans changer d’environnement.
*/

final class NotFoundExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Environment $twig) {}

    /**
     * Déclare l’écoute `kernel.exception` en priorité 250.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 250],
        ];
    }

    /**
     * Remplace la page 404 Symfony par le template personnalisé (y compris en dev).
     *
     * Comment : stoppe la propagation pour éviter la page debug sur les 404 métier.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof NotFoundHttpException) {
            return;
        }

        $html = $this->twig->render('bundles/TwigBundle/Exception/error404.html.twig');

        $event->setResponse(new Response($html, Response::HTTP_NOT_FOUND));
        $event->stopPropagation();
    }
}

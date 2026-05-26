<?php

namespace App\Twig;

use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/*
AdminExtension

QUOI : Fonction Twig `admin_pending_count()` pour afficher le badge « en attente » dans la sidebar admin.

COMMENT : Délègue à EventRepository::findForModeration ; appelée uniquement sur les pages admin.

OÙ : Templates `admin/*`.

POURQUOI : Éviter de passer le compteur en variable depuis chaque contrôleur.
*/

final class AdminExtension extends AbstractExtension
{
    public function __construct(private readonly EventRepository $events) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_pending_count', [$this, 'pendingCount']),
        ];
    }

    public function pendingCount(): int
    {
        return count($this->events->findForModeration(EventStatus::Pending));
    }
}

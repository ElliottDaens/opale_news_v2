<?php

namespace App\Enum;

/*
EventStatus

QUOI : Statuts de cycle de vie d’un événement côté modération (brouillon métier : en attente, validé, refusé).

COMMENT : Backed enum string ; `label()` pour l’UI admin, `isVisiblePublicly()` pour le filtrage public.

OÙ : Utilisé par `Event`, les repositories et les contrôleurs admin / soumission.

POURQUOI : Modéliser la visibilité et les transitions avant publication ou indexation Pinecone.
*/

enum EventStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Approuvé',
            self::Rejected => 'Refusé',
        };
    }

    public function isVisiblePublicly(): bool
    {
        return $this === self::Approved;
    }
}

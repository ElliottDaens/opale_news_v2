<?php

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/*
SoftDeleteFilter

QUOI : Filtre SQL Doctrine global qui masque les lignes dont la propriété `deletedAt` est non nulle.

COMMENT : Inspecte la réflexion de l’entité pour la colonne `deletedAt` ; désactivable via `getFilters()->disable()` pour la corbeille.

OÙ : Enregistré sous le nom `soft_deleted` dans la configuration Doctrine.

POURQUOI : Appliquer la suppression logique de façon transparente sur toutes les requêtes ORM.
*/

final class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->hasProperty('deletedAt')) {
            return '';
        }

        return sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }
}

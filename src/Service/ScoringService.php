<?php

namespace App\Service;

/*
ScoringService

QUOI : Conversion du score cosinus combiné (sémantique + bonus géo) en pourcentage de pertinence affichable.

COMMENT : Sigmoïde centrée sur 0.70 (zone utile des cosinus Gemini), plancher 50 et plafond 100, clamp final 0-100.

OÙ : Injecté dans `HomeController::buildEventRow` pour exposer `pertinence` aux résultats JSON.

POURQUOI : Isoler la logique mathématique pour qu'elle soit testable indépendamment du contrôleur HTTP.
*/

final class ScoringService
{
    public const SIGMOID_CENTER = 0.70;
    public const SIGMOID_STEEPNESS = 30.0;
    public const PERTINENCE_FLOOR = 50;
    public const PERTINENCE_CEILING = 100;

    /**
     * Convertit le score cosinus combiné (éventuellement avec bonus geo) en pourcentage 0-100.
     */
    public function scoreToPertinence(float $score): int
    {
        $sigmoid = 1.0 / (1.0 + exp(-self::SIGMOID_STEEPNESS * ($score - self::SIGMOID_CENTER)));
        $percent = self::PERTINENCE_FLOOR + $sigmoid * (self::PERTINENCE_CEILING - self::PERTINENCE_FLOOR);

        return (int) round(max(0.0, min(100.0, $percent)));
    }
}

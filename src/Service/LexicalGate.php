<?php

namespace App\Service;

use App\Entity\Event;

/*
LexicalGate

QUOI : Filet lexical pour rejeter les faux positifs sémantiques en zone grise (cosinus borderline).

COMMENT : Tokenise la requête (déaccentuation, lowercase, stopwords, longueur ≥ 4), puis vérifie qu'au moins
un token (ou sa racine courte) apparaît dans le titre + description + catégorie de l'événement.

OÙ : Appelé depuis `HomeController::classifyMatches*` pour les matches dont le `semanticScore` est dans la
fenêtre [seuil ; seuil + LEXICAL_BORDERLINE_WIDTH].

POURQUOI : Les embeddings Gemini bruitent fortement sur des queries courtes (1-2 mots) à cause du préfixe
de 51 caractères. Un signal lexical minimal évite que de la "poterie" remonte sur "sport".
*/
final class LexicalGate
{
    private const STOPWORDS = [
        'avec', 'sans', 'pour', 'dans', 'chez', 'plus', 'tout', 'tous', 'cette', 'cela',
        'mais', 'donc', 'leur', 'leurs', 'mon', 'ton', 'son', 'nos', 'vos', 'ses',
        'des', 'les', 'une', 'aux', 'que', 'qui', 'quoi', 'dont', 'sur', 'sous',
        'event', 'evenement', 'evenements', 'sortie', 'sorties', 'type', 'recherche',
        'cherche', 'trouve', 'trouver', 'voir', 'autour', 'pres', 'proche',
    ];

    /**
     * @return string[] Tokens normalisés (≥ 4 caractères, hors stopwords).
     */
    public function tokenize(string $query): array
    {
        $normalized = $this->normalize($query);
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 4) {
                continue;
            }
            if (in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Vérifie qu'au moins une racine de la requête (4 premiers caractères du token) apparaît
     * dans le titre, la description ou la catégorie de l'événement.
     *
     * Comment : on compare des racines de 4 caractères (matching minimal pour absorber les déclinaisons
     * "sport / sportif / sportive") sans tomber dans un faux match agressif (≥ 4 chars = pas de bruit
     * sur les mots-outils).
     */
    public function eventMatchesQuery(Event $event, string $query): bool
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            // Pas de signal lexical exploitable (query 1-2 chars, accents seulement) → on ne bloque pas.
            return true;
        }

        $haystack = $this->normalize(
            $event->getTitre() . ' ' . $event->getDescription() . ' ' . $event->getCategorie(),
        );

        foreach ($tokens as $token) {
            $root = mb_substr($token, 0, 4);
            if ($root !== '' && str_contains($haystack, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minuscules + suppression diacritiques + espaces simples.
     */
    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($stripped === false) {
            $stripped = $lower;
        }

        return preg_replace('/\s+/', ' ', $stripped) ?? $stripped;
    }
}

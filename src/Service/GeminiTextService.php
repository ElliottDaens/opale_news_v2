<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
GeminiTextService

QUOI : Appels au modèle génératif Gemini (catégorisation, reformulation, correction) pour le formulaire public.

COMMENT : Prompts structurés, boucle de retry sur `429` avec `RateLimitedException`, parsing minimal de la réponse texte.

OÙ : Injection dans `AiActionController` ; liste `CATEGORIES` partagée avec le formulaire.

POURQUOI : Encadrer les usages IA (quota, erreurs) sans exposer la clé API au navigateur.
*/

final class GeminiTextService
{
    private const MODEL = 'gemini-2.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent';

    private const RETRY_DELAYS = [2, 5];

    public const CATEGORIES = [
        'Musique', 'Sport', 'Culture', 'Gastronomie', 'Brocante',
        'Marché', 'Famille', 'Festival', 'Atelier', 'Conférence', 'Autre',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
    ) {}

    public function suggestCategory(string $titre, string $description): string
    {
        $categories = implode(', ', self::CATEGORIES);
        $prompt = <<<PROMPT
            Tu es un classificateur d'événements locaux pour la Côte d'Opale (Pas-de-Calais).
            Choisis UN SEUL mot parmi cette liste : {$categories}.

            Titre : "{$titre}"
            Description : "{$description}"

            Réponds uniquement par le mot exact de la liste, sans ponctuation ni explication.
            PROMPT;

        $result = trim($this->generate($prompt));

        foreach (self::CATEGORIES as $cat) {
            if (strcasecmp($result, $cat) === 0) {
                return $cat;
            }
        }

        return 'Autre';
    }

    public function improveDescription(string $text): string
    {
        $prompt = <<<PROMPT
            Améliore cette description d'événement local pour qu'elle soit plus engageante :
            - Ton chaleureux et accessible
            - Garde tous les faits (lieu, prix, horaires, contraintes)
            - Maximum 500 caractères
            - Pas d'emojis
            - Pas de superlatifs creux ("incroyable", "exceptionnel"…)

            Description originale :
            "{$text}"

            Réponds uniquement par le texte amélioré, sans préambule ni guillemets.
            PROMPT;

        return trim($this->generate($prompt));
    }

    public function correctText(string $text): string
    {
        $prompt = <<<PROMPT
            Corrige uniquement les fautes d'orthographe, de grammaire et de ponctuation dans ce texte.
            - Ne reformule rien
            - Ne change pas le sens
            - Garde la même longueur approximative
            - Garde le ton et le style

            Texte original :
            "{$text}"

            Réponds uniquement par le texte corrigé, sans préambule ni guillemets.
            PROMPT;

        return trim($this->generate($prompt));
    }

    private function generate(string $prompt): string
    {
        $attempts = 0;
        $maxAttempts = count(self::RETRY_DELAYS) + 1;

        while (true) {
            try {
                return $this->doRequest($prompt);
            } catch (RateLimitedException $e) {
                if (++$attempts >= $maxAttempts) {
                    throw $e;
                }
                sleep(self::RETRY_DELAYS[$attempts - 1] ?? 5);
            }
        }
    }

    private function doRequest(string $prompt): string
    {
        $response = $this->httpClient->request('POST', self::ENDPOINT . '?key=' . $this->apiKey, [
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1500,
                    // Gemini 2.5 consomme par défaut un budget « thinking » avant de répondre.
                    // Sur ces prompts courts de classification/reformulation, le raisonnement n'apporte rien
                    // et provoque parfois des réponses tronquées vides. On le coupe explicitement.
                    'thinkingConfig' => [
                        'thinkingBudget' => 0,
                    ],
                ],
            ],
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();

        if ($status === 429) {
            throw new RateLimitedException('Gemini rate limit (429).');
        }

        if ($status >= 400) {
            throw new GeminiException(sprintf('Gemini a renvoyé %d.', $status));
        }

        $data = $response->toArray(false);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}

final class RateLimitedException extends \RuntimeException {}
final class GeminiException extends \RuntimeException {}

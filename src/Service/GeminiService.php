<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
GeminiService

QUOI : Client HTTP pour les embeddings Google Gemini (`gemini-embedding-001`), avec cache applicatif.

COMMENT : POST vers l’API `embedContent`, dimensions 768, distinction `RETRIEVAL_QUERY` vs `RETRIEVAL_DOCUMENT`, préfixe contextuel sur les requêtes utilisateur.

OÙ : Utilisé par la recherche (`HomeController`) et `EventIndexer` pour alimenter Pinecone.

POURQUOI : Mutualiser les appels coûteux et garantir cohérence query/document dans l’espace latent.
*/

final class GeminiService
{
    private const MODEL = 'gemini-embedding-001';
    private const DIMENSIONS = 768;
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':embedContent';

    public const TASK_DOCUMENT = 'RETRIEVAL_DOCUMENT';
    public const TASK_QUERY = 'RETRIEVAL_QUERY';

    private const QUERY_PREFIX = 'Je cherche une sortie ou un événement de type : ';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire(env: 'GEMINI_API_KEY')] private readonly string $apiKey,
    ) {}

    /**
     * @return float[] vecteur d'embedding (768 dimensions)
     */
    public function getEmbedding(string $text, string $taskType = self::TASK_QUERY): array
    {
        $textForEmbedding = $taskType === self::TASK_QUERY
            ? self::QUERY_PREFIX . trim($text)
            : $text;

        $cacheKey = 'gemini_embed_' . hash(
            'xxh128',
            self::MODEL . '|' . self::QUERY_PREFIX . '|' . $taskType . '|' . $textForEmbedding,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($textForEmbedding, $taskType): array {
            $item->expiresAfter(60 * 60 * 24 * 30);

            $response = $this->httpClient->request('POST', self::ENDPOINT . '?key=' . $this->apiKey, [
                'json' => [
                    'content' => ['parts' => [['text' => $textForEmbedding]]],
                    'outputDimensionality' => self::DIMENSIONS,
                    'taskType' => $taskType,
                ],
                'timeout' => 30,
            ]);

            return $response->toArray()['embedding']['values'];
        });
    }
}

<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
PineconeService

QUOI : Client HTTP minimal pour l’index vectoriel Pinecone (upsert, delete, query).

COMMENT : En-têtes `Api-Key` et version API ; pas de métadonnées côté prototype hors ID scalaire.

OÙ : Appelé depuis `EventIndexer` et `HomeController` (recherche).

POURQUOI : Découpler les détails transport HTTP des cas d’usage métier (indexer, chercher).
*/

final class PineconeService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'PINECONE_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'PINECONE_INDEX_URL')] private readonly string $indexUrl,
    ) {}

    /**
     * @param float[]              $vector
     * @param array<string, mixed> $metadata Payload Pinecone (ex. `['featured' => true]`) — vide = pas de metadata.
     */
    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        $vectorPayload = ['id' => $id, 'values' => $vector];
        if ($metadata !== []) {
            $vectorPayload['metadata'] = $metadata;
        }

        $response = $this->httpClient->request('POST', $this->indexUrl . '/vectors/upsert', [
            'headers' => [
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2024-07',
            ],
            'json' => [
                'vectors' => [$vectorPayload],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Pinecone upsert failed for vector "%s": HTTP %d — %s',
                $id,
                $statusCode,
                $response->getContent(false),
            ));
        }
    }

    public function deleteAll(): void
    {
        $this->httpClient->request('POST', $this->indexUrl . '/vectors/delete', [
            'headers' => [
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2024-07',
            ],
            'json' => ['deleteAll' => true],
        ]);
    }

    public function deleteById(string $id): void
    {
        $this->httpClient->request('POST', $this->indexUrl . '/vectors/delete', [
            'headers' => [
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2024-07',
            ],
            'json' => ['ids' => [$id]],
        ]);
    }

    /**
     * @param float[] $vector
     *
     * @return array<array{id: int, score: float}>
     */
    public function query(array $vector, int $limit = 5): array
    {
        $response = $this->httpClient->request('POST', $this->indexUrl . '/query', [
            'headers' => [
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2024-07',
            ],
            'json' => [
                'vector' => $vector,
                'topK' => $limit,
                'includeValues' => false,
            ],
        ]);

        $data = $response->toArray();

        return array_map(
            static fn (array $match): array => [
                'id' => (int) $match['id'],
                'score' => (float) $match['score'],
            ],
            $data['matches'] ?? [],
        );
    }

    /**
     * Voisins sémantiques d'un vecteur déjà indexé (sans re-embedding).
     *
     * @return array<array{id: int, score: float}>
     */
    public function queryById(string $id, int $limit = 5): array
    {
        $response = $this->httpClient->request('POST', $this->indexUrl . '/query', [
            'headers' => [
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2024-07',
            ],
            'json' => [
                'id' => $id,
                'topK' => $limit,
                'includeValues' => false,
            ],
        ]);

        $data = $response->toArray();

        return array_map(
            static fn (array $match): array => [
                'id' => (int) $match['id'],
                'score' => (float) $match['score'],
            ],
            $data['matches'] ?? [],
        );
    }
}

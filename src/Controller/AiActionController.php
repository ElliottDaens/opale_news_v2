<?php

namespace App\Controller;

use App\Service\GeminiException;
use App\Service\GeminiTextService;
use App\Service\RateLimitedException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/*
AiActionController

QUOI : Endpoint JSON pour les assistants IA du formulaire (catégorie, réécriture, correction).

COMMENT : Rate limit `ai_action`, dispatch par `action` vers `GeminiTextService`, gestion 429 Gemini et erreurs réseau.

OÙ : `POST /api/ai-action`, consommé par `form-submission.js`.

POURQUOI : Externaliser la logique IA tout en isolant quotas et messages d’erreur utilisateur.
*/

final class AiActionController extends AbstractController
{
    #[Route('/api/ai-action', name: 'app_api_ai_action', methods: ['POST'])]
    public function aiAction(
        Request $request,
        GeminiTextService $ai,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.ai_action')] RateLimiterFactory $aiActionLimiter,
    ): JsonResponse {
        $limiter = $aiActionLimiter->create($request->getClientIp() ?? 'anon');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de demandes IA en peu de temps. Réessayez dans une minute.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('JSON body attendu.');
        }

        $action = $payload['action'] ?? null;

        try {
            $result = match ($action) {
                'suggest_category' => $ai->suggestCategory(
                    (string) ($payload['titre'] ?? ''),
                    (string) ($payload['description'] ?? ''),
                ),
                'improve' => $ai->improveDescription((string) ($payload['text'] ?? '')),
                'correct' => $ai->correctText((string) ($payload['text'] ?? '')),
                default => throw new BadRequestHttpException('Action inconnue.'),
            };
        } catch (RateLimitedException $e) {
            $logger->warning('Gemini rate limit hit', ['action' => $action]);

            return $this->json(
                ['error' => 'L\'IA est temporairement saturée. Réessayez dans 1-2 minutes.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (GeminiException $e) {
            $logger->error('Gemini error', ['action' => $action, 'message' => $e->getMessage()]);

            return $this->json(
                ['error' => 'L\'IA n\'a pas pu traiter votre demande. Réessayez.'],
                Response::HTTP_BAD_GATEWAY,
            );
        } catch (\Throwable $e) {
            $logger->error('AI action failed', ['action' => $action, 'exception' => $e]);

            return $this->json(
                ['error' => 'Erreur inattendue côté IA.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        if (trim($result) === '') {
            return $this->json(
                ['error' => 'L\'IA a renvoyé une réponse vide.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return $this->json(['result' => $result]);
    }
}

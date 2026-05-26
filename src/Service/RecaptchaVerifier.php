<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
RecaptchaVerifier

QUOI : Validation serveur des jetons reCAPTCHA v3 via l’API `siteverify` de Google.

COMMENT : POST form-urlencoded, seuil de score minimal, tolère les erreurs réseau par échec silencieux.

OÙ : Appelé dans `EventSubmissionController` après soumission formulaire.

POURQUOI : Réduire le spam automatisé sur la proposition d’événements sans friction UX lourde.
*/

final class RecaptchaVerifier
{
    private const MIN_SCORE = 0.5;
    private const SITE_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'RECAPTCHA_SECRET_KEY')] private readonly string $secretKey,
    ) {}

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::SITE_VERIFY_URL, [
                'body' => [
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp ?? '',
                ],
                'timeout' => 10,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return false;
        }

        if (($data['success'] ?? false) !== true) {
            return false;
        }

        $score = (float) ($data['score'] ?? 0);

        return $score >= self::MIN_SCORE;
    }
}

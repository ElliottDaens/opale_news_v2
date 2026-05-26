<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
GeoService

QUOI : Calcul de distance sphérique, facteur de proximité, et fallback de géocodage serveur via Google Geocoding API.

COMMENT : Formule de Haversine ; `proximityFactor` renvoie 1 à 0 sur un rayon paramétrable ; `geocodeAddress` appelle Google Maps avec timeout court et avale les erreurs.

OÙ : Injecté dans `HomeController` (proximité) et `EventSubmissionController` (fallback géocodage si JS client indisponible).

POURQUOI : Pondérer géographiquement les résultats sémantiques sans deuxième appel externe, et garantir des coordonnées même si le géocodage front échoue.
*/

final class GeoService
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const GEOCODE_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'GMAPS_API_KEY')] private readonly string $gmapsApiKey,
    ) {}

    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    public function proximityFactor(float $distanceKm, float $radiusKm = 50.0): float
    {
        if ($distanceKm <= 0.0) {
            return 1.0;
        }
        if ($distanceKm >= $radiusKm) {
            return 0.0;
        }

        return 1.0 - ($distanceKm / $radiusKm);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocodeAddress(string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || $this->gmapsApiKey === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::GEOCODE_ENDPOINT, [
                'query' => [
                    'address' => $address,
                    'key' => $this->gmapsApiKey,
                    'region' => 'fr',
                    'components' => 'country:FR',
                ],
                'timeout' => 5,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        if (($data['status'] ?? null) !== 'OK') {
            return null;
        }

        $location = $data['results'][0]['geometry']['location'] ?? null;
        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
        ];
    }
}

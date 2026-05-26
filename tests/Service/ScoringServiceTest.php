<?php

namespace App\Tests\Service;

use App\Service\ScoringService;
use PHPUnit\Framework\TestCase;

/*
ScoringServiceTest

QUOI : Tests unitaires de la sigmoïde `scoreToPertinence` (plancher, centre, plafond, effet boost geo).

POURQUOI : Garantir que la conversion cosinus → pourcentage reste stable lors de refactos futurs.
*/

final class ScoringServiceTest extends TestCase
{
    private ScoringService $scoring;

    protected function setUp(): void
    {
        $this->scoring = new ScoringService();
    }

    public function testVeryLowScoreReturnsFloor(): void
    {
        // Score très en dessous du centre 0.70 : sigmoïde ≈ 0, on doit toucher le plancher 50.
        self::assertSame(50, $this->scoring->scoreToPertinence(0.30));
    }

    public function testScoreAtCenterReturnsMidPoint(): void
    {
        // Au centre 0.70 : sigmoïde = 0.5, percent = 50 + 0.5 * 50 = 75.
        self::assertSame(75, $this->scoring->scoreToPertinence(0.70));
    }

    public function testPerfectScoreReachesCeiling(): void
    {
        // Cosinus parfait 1.0 : la sigmoïde sature, on doit toucher le plafond 100.
        self::assertSame(100, $this->scoring->scoreToPertinence(1.00));
    }

    public function testGeoBoostLiftsPertinenceAcrossCenter(): void
    {
        // Score sémantique 0.69 (juste sous le centre) vs. score boosté de PROXIMITY_BOOST_MAX = 0.06.
        // Le boost géo doit produire un pourcentage strictement supérieur (la sigmoïde est la plus pentue ici).
        $withoutBoost = $this->scoring->scoreToPertinence(0.69);
        $withBoost = $this->scoring->scoreToPertinence(0.69 + 0.06);

        self::assertSame(71, $withoutBoost);
        self::assertSame(91, $withBoost);
        self::assertGreaterThan($withoutBoost, $withBoost);
    }

    public function testResultIsAlwaysClampedBetween0And100(): void
    {
        // Garde-fou : même des entrées extrêmes restent dans [0, 100].
        $low = $this->scoring->scoreToPertinence(-5.0);
        $high = $this->scoring->scoreToPertinence(10.0);

        self::assertGreaterThanOrEqual(0, $low);
        self::assertLessThanOrEqual(100, $high);
    }
}

<?php

namespace App\Tests\Service;

use App\Service\TemporalQueryParser;
use PHPUnit\Framework\TestCase;

/*
TemporalQueryParserTest

QUOI : Tests unitaires de l'extraction d'expressions temporelles françaises (jour, week-end, semaine, mois nommé) et du nettoyage de la requête associée.

POURQUOI : Verrouiller le contrat `parse()`/`stripPhrase()` utilisé par `HomeController::search` avant l'appel embedding Gemini.
*/

final class TemporalQueryParserTest extends TestCase
{
    private TemporalQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TemporalQueryParser();
    }

    public function testQueryWithoutTemporalHintReturnsNull(): void
    {
        // Pas de marqueur calendaire : le parser doit s'effacer et laisser la recherche purement sémantique.
        self::assertNull($this->parser->parse('concert de rock'));
    }

    public function testEmptyQueryReturnsNull(): void
    {
        self::assertNull($this->parser->parse(''));
    }

    public function testCeSoirIsTreatedAsToday(): void
    {
        $today = new \DateTimeImmutable('today');

        $result = $this->parser->parse('ce soir');

        self::assertNotNull($result);
        self::assertSame('aujourd\'hui', $result['label']);
        self::assertSame($today->format('Y-m-d'), $result['from']->format('Y-m-d'));
        self::assertSame($today->format('Y-m-d'), $result['to']->format('Y-m-d'));
        self::assertSame('ce soir', strtolower($result['phrase']));
    }

    public function testAujourdHuiIsRecognised(): void
    {
        $today = new \DateTimeImmutable('today');

        $result = $this->parser->parse('quoi faire aujourd\'hui');

        self::assertNotNull($result);
        self::assertSame('aujourd\'hui', $result['label']);
        self::assertSame($today->format('Y-m-d'), $result['from']->format('Y-m-d'));
    }

    public function testDemainShiftsByOneDay(): void
    {
        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day');

        $result = $this->parser->parse('demain');

        self::assertNotNull($result);
        self::assertSame('demain', $result['label']);
        self::assertSame($tomorrow->format('Y-m-d'), $result['from']->format('Y-m-d'));
        self::assertSame($tomorrow->format('Y-m-d'), $result['to']->format('Y-m-d'));
    }

    public function testApresDemainShiftsByTwoDays(): void
    {
        // "après-demain" doit être détecté avant "demain" sinon la sous-chaîne serait avalée.
        $expected = (new \DateTimeImmutable('today'))->modify('+2 days');

        $result = $this->parser->parse('un événement après-demain');

        self::assertNotNull($result);
        self::assertSame('après-demain', $result['label']);
        self::assertSame($expected->format('Y-m-d'), $result['from']->format('Y-m-d'));
    }

    public function testWeekEndProducesSaturdayToSunday(): void
    {
        // La date courante varie selon l'exécution : on vérifie la nature des bornes plutôt que des dates absolues.
        $result = $this->parser->parse('ce week-end');

        self::assertNotNull($result);
        self::assertSame('ce week-end', $result['label']);
        self::assertSame('6', $result['from']->format('N'), 'from doit être un samedi');
        self::assertSame('7', $result['to']->format('N'), 'to doit être un dimanche');
        // De plus, samedi puis dimanche => écart d'un jour exactement.
        self::assertSame(1, (int) $result['from']->diff($result['to'])->format('%a'));
    }

    public function testEnJuilletReturnsFullMonth(): void
    {
        $result = $this->parser->parse('en juillet');

        self::assertNotNull($result);
        self::assertSame('en juillet', $result['label']);
        self::assertSame('07', $result['from']->format('m'));
        self::assertSame('01', $result['from']->format('d'));
        self::assertSame('07', $result['to']->format('m'));
        self::assertSame('31', $result['to']->format('d'));
        // L'année de fin doit être identique à celle de début (juillet n'enjambe pas).
        self::assertSame($result['from']->format('Y'), $result['to']->format('Y'));
    }

    public function testCetteSemaineEndsOnNextSunday(): void
    {
        $today = new \DateTimeImmutable('today');

        $result = $this->parser->parse('cette semaine');

        self::assertNotNull($result);
        self::assertSame('cette semaine', $result['label']);
        self::assertSame($today->format('Y-m-d'), $result['from']->format('Y-m-d'));
        self::assertSame('7', $result['to']->format('N'), 'to doit être un dimanche');
        self::assertGreaterThanOrEqual($result['from'], $result['to']);
    }

    public function testMoisProchainSpansFullNextMonth(): void
    {
        $now = new \DateTimeImmutable('today');
        $expectedStart = $now->modify('first day of next month');
        $expectedEnd = $now->modify('last day of next month');

        $result = $this->parser->parse('le mois prochain');

        self::assertNotNull($result);
        self::assertSame('le mois prochain', $result['label']);
        self::assertSame($expectedStart->format('Y-m-d'), $result['from']->format('Y-m-d'));
        self::assertSame($expectedEnd->format('Y-m-d'), $result['to']->format('Y-m-d'));
    }

    public function testMixedQueryIsolatesTemporalSegment(): void
    {
        // Cas mixte clé : "sortie famille en juillet" doit isoler la date sans saccager le texte sémantique.
        $query = 'sortie famille en juillet';

        $result = $this->parser->parse($query);

        self::assertNotNull($result);
        self::assertSame('en juillet', strtolower($result['phrase']));

        $cleaned = $this->parser->stripPhrase($query, $result['phrase']);
        self::assertSame('sortie famille', $cleaned);
    }

    public function testStripPhraseRemovesTemporalWordsAndNormalizesSpaces(): void
    {
        $result = $this->parser->parse('concert demain');
        self::assertNotNull($result);

        $cleaned = $this->parser->stripPhrase('concert demain', $result['phrase']);
        self::assertSame('concert', $cleaned);
    }

    public function testStripPhraseRemovesTrailingOrphanPreposition(): void
    {
        // "festival pour ce week-end" -> après suppression de "ce week-end", "pour" reste orphelin et doit être nettoyé.
        $cleaned = $this->parser->stripPhrase('festival pour ce week-end', 'ce week-end');

        self::assertSame('festival', $cleaned);
    }
}

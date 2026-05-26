<?php

namespace App\Service;

/*
TemporalQueryParser

QUOI : Détection d’intentions temporelles en français dans une requête libre (jour, semaine, mois, saison, jour de la semaine).

COMMENT : Successions de `preg_match` sur la chaîne ; produit une fenêtre `from`/`to`, une `phrase` à retirer pour l’embedding et un `label` d’affichage.

OÙ : Utilisé par `HomeController::search` avant l’appel embedding Gemini.

POURQUOI : Combiner filtre calendrier et recherche sémantique sans parser NLP externe.
*/

final class TemporalQueryParser
{
    private const MONTHS = [
        'janvier' => 1, 'février' => 2, 'fevrier' => 2,
        'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11,
        'décembre' => 12, 'decembre' => 12,
    ];

    private const DAYS_FR_EN = [
        'lundi' => 'monday', 'mardi' => 'tuesday', 'mercredi' => 'wednesday',
        'jeudi' => 'thursday', 'vendredi' => 'friday',
        'samedi' => 'saturday', 'dimanche' => 'sunday',
    ];

    /**
     * Analyse la requête brute et, si une plage calendaire française est reconnue, renvoie bornes + extrait + libellé.
     *
     * Comment : enchaîne des motifs exclusifs (aujourd’hui, demain, week-end, semaine, mois, saison, mois nommé, jour de la semaine) ; construit `[from, to]` avec `DateTimeImmutable` et le texte matché pour `stripPhrase`.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, phrase: string, label: string}|null
     */
    public function parse(string $query): ?array
    {
        $now = new \DateTimeImmutable('today');

        if (preg_match('/\b(aujourd[\'\xe2\x80\x99]?hui|ce\s+soir|cet?\s+apr[èe]s[-\s]midi)\b/iu', $query, $m)) {
            return $this->result($now, $now, $m[0], 'aujourd\'hui');
        }

        if (preg_match('/\bapr[èe]s[-\s]demain\b/iu', $query, $m)) {
            $d = $now->modify('+2 days');

            return $this->result($d, $d, $m[0], 'après-demain');
        }

        if (preg_match('/\bdemain\b/iu', $query, $m)) {
            $d = $now->modify('+1 day');

            return $this->result($d, $d, $m[0], 'demain');
        }

        if (preg_match('/\b(ce|le)\s+(week[-\s]?end|w[-\s]?e)\b/iu', $query, $m)) {
            $dow = (int) $now->format('N');
            if ($dow >= 6) {
                $sat = $dow === 6 ? $now : $now->modify('-1 day');
            } else {
                $sat = $now->modify('next saturday');
            }
            $sun = $sat->modify('+1 day');

            return $this->result($sat, $sun, $m[0], 'ce week-end');
        }

        if (preg_match('/\bcette\s+semaine\b/iu', $query, $m)) {
            $sun = $now->modify('sunday this week');
            if ($sun < $now) {
                $sun = $now->modify('next sunday');
            }

            return $this->result($now, $sun, $m[0], 'cette semaine');
        }

        if (preg_match('/\b(la\s+)?semaine\s+prochaine\b/iu', $query, $m)) {
            $mon = $now->modify('next monday');
            $sun = $mon->modify('+6 days');

            return $this->result($mon, $sun, $m[0], 'la semaine prochaine');
        }

        if (preg_match('/\bce\s+mois(?:[-\s]ci)?\b/iu', $query, $m)) {
            $end = $now->modify('last day of this month');

            return $this->result($now, $end, $m[0], 'ce mois-ci');
        }

        if (preg_match('/\b(le\s+)?mois\s+prochain\b/iu', $query, $m)) {
            $start = $now->modify('first day of next month');
            $end = $now->modify('last day of next month');

            return $this->result($start, $end, $m[0], 'le mois prochain');
        }

        $seasons = [
            'été' => [6, 21, 9, 22, 'cet été'],
            'ete' => [6, 21, 9, 22, 'cet été'],
            'hiver' => [12, 21, 3, 19, 'cet hiver'],
            'printemps' => [3, 20, 6, 20, 'ce printemps'],
            'automne' => [9, 23, 12, 20, 'cet automne'],
        ];
        foreach ($seasons as $name => [$m1, $d1, $m2, $d2, $label]) {
            if (preg_match('/\b(cet?|ce|l[\'\xe2\x80\x99]\s*)\s*' . $name . '\b/iu', $query, $m)) {
                $year = (int) $now->format('Y');
                if ($name === 'hiver' && (int) $now->format('n') < 12) {
                    $startYear = $year - 1;
                    $endYear = $year;
                } else {
                    $startYear = $year;
                    $endYear = $year;
                    if ($m2 < $m1) {
                        ++$endYear;
                    }
                }
                $start = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $startYear, $m1, $d1));
                $end = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $endYear, $m2, $d2));

                return $this->result($start, $end, $m[0], $label);
            }
        }

        foreach (self::MONTHS as $name => $num) {
            if (preg_match('/\b(?:en|au\s+mois\s+d[\'\xe2\x80\x99]e?|d[\'\xe2\x80\x99])\s*' . $name . '\b/iu', $query, $m)) {
                [$start, $end] = $this->monthRange($num, $now);

                return $this->result($start, $end, $m[0], 'en ' . $name);
            }
        }

        foreach (self::DAYS_FR_EN as $fr => $en) {
            if (preg_match('/\b' . $fr . '(\s+prochain)?\b/iu', $query, $m)) {
                $next = $now->modify('next ' . $en);

                return $this->result($next, $next, $m[0], $fr . ' prochain');
            }
        }

        return null;
    }

    /**
     * Retire de la requête la sous-chaîne temporelle détectée pour ne pas polluer l’embedding textuel.
     *
     * Comment : `str_ireplace` de la phrase, normalise les espaces, enlève une petite préposition orpheline en fin de chaîne.
     */
    public function stripPhrase(string $query, string $phrase): string
    {
        $cleaned = trim(preg_replace('/\s+/', ' ', str_ireplace($phrase, ' ', $query)));
        $cleaned = preg_replace('/\b(pour|en|pendant)\s*$/iu', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Calcule le premier et dernier jour du mois nommé, par rapport à « aujourd’hui » (année suivante si le mois est déjà passé).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthRange(int $month, \DateTimeImmutable $now): array
    {
        $year = (int) $now->format('Y');
        if ($month < (int) $now->format('n')) {
            ++$year;
        }
        $start = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');

        return [$start, $end];
    }

    /**
     * Fabrique la structure uniforme renvoyée par `parse` pour le reste du pipeline recherche.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, phrase: string, label: string}
     */
    private function result(\DateTimeImmutable $from, \DateTimeImmutable $to, string $phrase, string $label): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'phrase' => $phrase,
            'label' => $label,
        ];
    }
}

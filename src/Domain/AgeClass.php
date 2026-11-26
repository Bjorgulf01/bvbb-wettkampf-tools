<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Altersklasse nach Kalenderjahr: Alter = Jahr minus Jahrgang.
 * U09 … U19 gelten bis unter 9, 11, 13, 15, 17, 19 Jahren, danach O19.
 * Ohne brauchbaren Jahrgang bleibt die AK leer; die Datenprüfung meldet dann missing_birth_year.
 * Eine AK aus der Liste wird nicht übernommen (Entwurf „Kaderliste rund machen“).
 */
final class AgeClass
{
    private const LIMITS = [9, 11, 13, 15, 17, 19];

    /** Eigenständige Jahreszahl 19xx oder 20xx (nicht Teil einer längeren Zahl). */
    private const YEAR = '/(?<!\d)(?:19|20)\d{2}(?!\d)/';

    /** @return string 'U09' … 'U19', 'O19' oder '' (kein brauchbarer Jahrgang) */
    public static function for(?string $birthYearOrDate, int $year): string
    {
        // Jahrgang oder Datum: die erste eigenständige Jahreszahl 19xx/20xx ist das Geburtsjahr.
        // Ein Jahrgang nach dem Turnierjahr ist unbrauchbar und zählt wie ein fehlender.
        if (preg_match(self::YEAR, (string) $birthYearOrDate, $m) !== 1 || (int) $m[0] > $year) {
            return '';
        }
        $age = $year - (int) $m[0];
        foreach (self::LIMITS as $limit) {
            if ($age < $limit) {
                return 'U' . str_pad((string) $limit, 2, '0', STR_PAD_LEFT);
            }
        }
        return 'O19';
    }

    /** Letzte Jahreszahl (19xx oder 20xx) im Termin, sonst das Jahr von $today. */
    public static function yearFromTerm(string $term, DateTimeImmutable $today): int
    {
        if (preg_match_all(self::YEAR, $term, $m) > 0) {
            return (int) end($m[0]);
        }
        return (int) $today->format('Y');
    }
}

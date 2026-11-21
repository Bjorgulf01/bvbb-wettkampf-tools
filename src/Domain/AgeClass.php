<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Altersklasse nach Kalenderjahr: Alter = Jahr minus Jahrgang.
 * U09 … U19 gelten bis unter 9, 11, 13, 15, 17, 19 Jahren, danach O19.
 * Ohne Jahrgang gilt die AK aus der Liste (U22 und O22 werden zu O19).
 */
final class AgeClass
{
    private const LIMITS = [9, 11, 13, 15, 17, 19];

    /** Eigenständige Jahreszahl 19xx oder 20xx (nicht Teil einer längeren Zahl). */
    private const YEAR = '/(?<!\d)(?:19|20)\d{2}(?!\d)/';

    /** @return string 'U09' … 'U19', 'O19' oder '' (nicht bestimmbar) */
    public static function for(?string $birthYearOrDate, string $listAk, int $year): string
    {
        // Jahrgang oder Datum: die erste eigenständige Jahreszahl 19xx/20xx ist das Geburtsjahr.
        // Ein Jahrgang nach dem Turnierjahr ist unbrauchbar und zählt wie ein fehlender.
        if (preg_match(self::YEAR, (string) $birthYearOrDate, $m) === 1 && (int) $m[0] <= $year) {
            $age = $year - (int) $m[0];
            foreach (self::LIMITS as $limit) {
                if ($age < $limit) {
                    return self::u($limit);
                }
            }
            return 'O19';
        }
        return self::fromList($listAk);
    }

    /** Letzte Jahreszahl (19xx oder 20xx) im Termin, sonst das Jahr von $today. */
    public static function yearFromTerm(string $term, DateTimeImmutable $today): int
    {
        if (preg_match_all(self::YEAR, $term, $m) > 0) {
            return (int) end($m[0]);
        }
        return (int) $today->format('Y');
    }

    private static function fromList(string $listAk): string
    {
        $ak = strtoupper(preg_replace('/\s+/u', '', $listAk) ?? '');
        if ($ak === '') {
            return '';
        }
        if (preg_match('/^([UO])(\d{1,2})$/', $ak, $m) !== 1) {
            return '';
        }
        $n = (int) $m[2];
        if ($m[1] === 'O' || $n > 19) {
            return 'O19';
        }
        // Nur die gültigen Klassen; ein Wert wie U10 bleibt leer und fällt in der Datenprüfung auf.
        return in_array($n, self::LIMITS, true) ? self::u($n) : '';
    }

    private static function u(int $limit): string
    {
        return 'U' . str_pad((string) $limit, 2, '0', STR_PAD_LEFT);
    }
}

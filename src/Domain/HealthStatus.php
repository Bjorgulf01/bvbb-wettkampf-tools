<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Ampel für NADA-Schulung und sportmedizinische Untersuchung aus den Textspalten
 * „SpoMed“ und „Anti Doping“ der Kaderliste (bis Etappe 3).
 * Ergebnis: done (erledigt), scheduled (terminiert), open (offen), unclear (unklar).
 * Der Rohtext kann Namen und Gründe enthalten und wird hier weder gespeichert noch ausgegeben.
 */
final class HealthStatus
{
    public const DONE = 'done';
    public const SCHEDULED = 'scheduled';
    public const OPEN = 'open';
    public const UNCLEAR = 'unclear';

    /** Vollständiges Datum TT.MM.JJJJ oder TT.MM.JJ (Tag und Monat auch einstellig). */
    private const FULL_DATE = '/(?<!\d)(\d{1,2})\.(\d{1,2})\.(\d{4}|\d{2})(?!\d)/';

    /**
     * Regeln von oben nach unten, der erste Treffer zählt (Plan, Task 4b).
     * „Erledigt“ (Satzzeichen am Ende egal) und „/“ gelten nur als ganzer Eintrag, „abgesagt“ und
     * die übrigen Stichwörter auch als Teil des Textes. Eine Absage geht Termin und Datum vor,
     * damit eine abgesagte Untersuchung nie grün oder gelb erscheint.
     */
    public static function fromNote(?string $note, int $year, DateTimeImmutable $today): string
    {
        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $note);
        if ($collapsed === null) {
            return self::UNCLEAR; // kein gültiges UTF-8
        }
        $text = mb_strtolower(trim($collapsed), 'UTF-8');

        if ($text === '') {
            return self::OPEN;
        }
        if (preg_replace('/[\s\p{P}\p{S}]+$/u', '', $text) === 'erledigt') {
            return self::DONE; // gilt für das Jahr, in dem die Liste gepflegt wird
        }
        if ($text === '/' || str_contains($text, 'abgesagt')) {
            return self::OPEN; // „/“ = noch nichts gepflegt
        }
        if (str_contains($text, 'kein')) {
            return self::UNCLEAR;
        }
        foreach (['angefragt', 'kommt bald', 'termin'] as $word) {
            if (str_contains($text, $word)) {
                return self::SCHEDULED;
            }
        }

        $date = self::latestFullDate($text);
        if ($date !== null) {
            $dateYear = (int) $date->format('Y');
            if ($dateYear === $year) {
                return $date->format('Y-m-d') > $today->format('Y-m-d') ? self::SCHEDULED : self::DONE;
            }
            if ($dateYear < $year) {
                return self::OPEN; // fällig im Jahr $year
            }
            return self::UNCLEAR; // Datum nach dem betrachteten Jahr: vermutlich Tippfehler
        }

        // Datum ohne Jahr („23.2.“), ungültiges Datum und jeder andere Text.
        return self::UNCLEAR;
    }

    /** Spätestes gültiges vollständiges Datum im Text, sonst null. */
    private static function latestFullDate(string $text): ?DateTimeImmutable
    {
        if (preg_match_all(self::FULL_DATE, $text, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        $latest = null;
        foreach ($m as [, $d, $mo, $y]) {
            $y = strlen($y) === 2 ? 2000 + (int) $y : (int) $y;
            if (!checkdate((int) $mo, (int) $d, $y)) {
                continue;
            }
            $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, (int) $mo, (int) $d));
            if ($latest === null || $date > $latest) {
                $latest = $date;
            }
        }
        return $latest;
    }
}

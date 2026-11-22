<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Datenprüfung der Kaderliste (Regeln aus dem Prototyp, Version 1.5.0, ergänzt um ungültige
 * Jahrgänge und Listen-AK aus Task 3). Meldet Auffälligkeiten, ändert nichts.
 * Passive Spieler werden mitgeprüft. Gesundheitsinhalte kommen in den Befunden nie vor.
 */
final class PlayerValidator
{
    /** Reihenfolge der Befunde in der Ausgabe. */
    public const CODES = ['missing_birth_year', 'bad_birth_year', 'missing_club', 'unknown_club', 'bad_gender',
        'missing_roster_status', 'ak_mismatch', 'bad_list_ak', 'training_site_looks_like_ak', 'duplicate_name'];

    private const GENDERS = ['m', 'w'];

    /** Leerraum einschließlich geschütztem Leerzeichen, Null-Breite-Zeichen und Byte-Order-Mark. */
    private const SPACE = '[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]';

    /**
     * @param list<array<string, mixed>> $players Felder wie in der Datenbank (fehlende Schlüssel gelten als leer)
     * @return list<array{code:string, id:?int, player:string, detail:string}>
     */
    public static function issues(array $players, int $year, ClubDirectory $clubs): array
    {
        $found = array_fill_keys(self::CODES, []);
        $names = [];
        foreach ($players as $p) {
            $names[self::key($p)] = ($names[self::key($p)] ?? 0) + 1;
        }

        foreach ($players as $p) {
            $add = static function (string $code, string $detail = '') use (&$found, $p): void {
                $id = $p['id'] ?? null;
                $found[$code][] = [
                    'code' => $code,
                    'id' => $id !== null ? (int) $id : null,
                    'player' => self::text($p['last_name'] ?? null) . ', ' . self::text($p['first_name'] ?? null),
                    'detail' => $detail,
                ];
            };

            $by = $p['birth_year'] ?? null;
            $by = is_int($by) || (is_string($by) && preg_match('/^\d{4}$/', $by) === 1) ? (int) $by : null;
            $listAk = self::text($p['list_ak'] ?? null);
            $listClass = AgeClass::for(null, $listAk, $year);

            if ($by === null) {
                $add('missing_birth_year');
            } elseif ($by > $year || $by < 1900) {
                $add('bad_birth_year', 'Jahrgang ' . $by);
                $by = null;
            }

            $club = self::text($p['club_name'] ?? null);
            if ($club === '') {
                $add('missing_club');
            } elseif (!$clubs->lookup($club)['known']) {
                $add('unknown_club', $club);
            }

            $gender = self::text($p['gender'] ?? null);
            if (!in_array($gender, self::GENDERS, true)) {
                $add('bad_gender', $gender);
            }

            if (self::text($p['roster_status'] ?? null) === '') {
                $add('missing_roster_status');
            }

            if ($listAk !== '' && $listClass === '') {
                $add('bad_list_ak', 'Liste ' . $listAk);
            } elseif ($by !== null && $listClass !== '') {
                $computed = AgeClass::for((string) $by, '', $year);
                if ($computed !== $listClass) {
                    $add('ak_mismatch', 'Liste ' . $listAk . ', berechnet ' . $computed);
                }
            }

            $site = self::text($p['training_site'] ?? null);
            if (preg_match('/^[UO]\s*\d/iu', $site) === 1) {
                $add('training_site_looks_like_ak', $site);
            }

            if ($names[self::key($p)] > 1) {
                $add('duplicate_name');
            }
        }
        return array_merge(...array_values($found));
    }

    /** Vergleichsschlüssel aus Name und Vorname (wie beim Import). */
    public static function nameKey(mixed $lastName, mixed $firstName): string
    {
        $fold = static fn(mixed $v): string => mb_convert_case(
            preg_replace('/' . self::SPACE . '+/u', ' ', self::text($v)) ?? '', MB_CASE_FOLD, 'UTF-8');
        return $fold($lastName) . "\u{0000}" . $fold($firstName);
    }

    /** @param array<string, mixed> $p */
    private static function key(array $p): string
    {
        return self::nameKey($p['last_name'] ?? null, $p['first_name'] ?? null);
    }

    /** Text ohne Leerraum am Rand; null und Nicht-Texte ergeben ''. */
    private static function text(mixed $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        return preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $v) ?? '';
    }
}

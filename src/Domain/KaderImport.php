<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Excel-Import der Kaderliste (Übergang). Der Browser liest die Datei und schickt Zeilen mit den
 * Feldnamen der Schnittstelle. Hier wird nur normalisiert und geplant, nichts gespeichert.
 * Abgleich mit vorhandenen Spielern über Nachname und Vorname ohne Groß-/Kleinschreibung.
 * Ein Schlüssel, der in einer Zeile fehlt, ändert den Wert nicht; ein leerer Wert leert ihn.
 */
final class KaderImport
{
    /** Größte Excel-Seriennummer, die als Geburtsdatum gedeutet wird (31.12.2099). */
    private const MAX_SERIAL = 73050;

    /** Felder, die der Import nicht mehr übernimmt (AK aus der Liste: Spalte bleibt in der Datenbank). */
    private const NOT_IMPORTED = ['list_ak'];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $field => $value) {
            if (is_string($value)) {
                $value = preg_replace(PlayerInput::EDGE_SPACE, '', $value) ?? $value;
                if ($value === '') {
                    $value = null;
                }
            } elseif (is_float($value) && floor($value) === $value && abs($value) < 1e9) {
                $value = (int) $value; // Excel liefert Zahlen als Gleitkommazahl
            }
            // Zahlen in Textfeldern (Telefon, T-Shirt-Größe) als Text.
            if (is_int($value) && (PlayerInput::RULES[$field][0] ?? null) === 'string') {
                $value = (string) $value;
            }
            $out[$field] = $value;
        }

        foreach (['list_no', 'birth_year'] as $f) {
            if (is_string($out[$f] ?? null) && preg_match('/^\d{1,9}$/', $out[$f]) === 1) {
                $out[$f] = (int) $out[$f];
            }
        }
        if (array_key_exists('active', $out)) {
            $a = $out['active'];
            if ($a === null) {
                unset($out['active']); // leerer Status ändert nichts, neue Spieler sind aktiv
            } elseif (is_string($a) && in_array(mb_strtolower($a, 'UTF-8'), ['aktiv', 'passiv'], true)) {
                $out['active'] = mb_strtolower($a, 'UTF-8') === 'aktiv';
            }
        }
        if (array_key_exists('birth_date', $out)) {
            self::birthDate($out);
        }
        return $out;
    }

    /**
     * @param list<mixed> $rows Zeilen aus der Datei (Nummer in skipped: Position ab 1)
     * @param list<array{id:int, last_name:string, first_name:string, birth_date?:?string}> $existing
     *     birth_date: gespeicherter Geburtstag, für den Abgleich des Jahrgangs (BirthYear)
     * @return array{create: list<array<string, mixed>>, update: list<array{id:int, data:array<string, mixed>}>,
     *     skipped: list<array{row:int, reason:string, fields?:list<string>}>}
     */
    public static function plan(array $rows, array $existing): array
    {
        $byName = [];
        $storedDates = [];
        foreach ($existing as $e) {
            $byName[PlayerValidator::nameKey($e['last_name'], $e['first_name'])][] = (int) $e['id'];
            $storedDates[(int) $e['id']] = $e['birth_date'] ?? null;
        }
        $plan = ['create' => [], 'update' => [], 'skipped' => []];
        $seen = [];
        $order = array_flip(array_values(array_diff(array_keys(PlayerInput::RULES), self::NOT_IMPORTED)));

        foreach (array_values($rows) as $i => $raw) {
            $no = $i + 1;
            if (!is_array($raw) || array_is_list($raw) && $raw !== [] || array_diff_key($raw, $order) !== []) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'invalid'];
                continue;
            }
            $row = self::normalizeRow($raw);
            if (array_filter($row, static fn(mixed $v): bool => $v !== null) === []) {
                continue; // völlig leere Zeile (zum Beispiel am Ende des Blatts): still übergehen
            }
            $last = $row['last_name'] ?? null;
            if (($row['gender'] ?? null) === 'O22' || is_string($last) && preg_match('/^Legende/u', $last) === 1) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'placeholder'];
                continue;
            }
            if ($last === null) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'missing_last_name'];
                continue;
            }
            if (($row['first_name'] ?? null) === null) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'missing_first_name'];
                continue;
            }
            $key = PlayerValidator::nameKey($last, $row['first_name']);
            [$data, $invalid] = PlayerInput::validate($row);
            if ($invalid !== []) {
                usort($invalid, static fn(string $a, string $b): int => $order[$a] <=> $order[$b]);
                $plan['skipped'][] = ['row' => $no, 'reason' => 'invalid', 'fields' => $invalid];
                continue;
            }
            $ids = $byName[$key] ?? [];
            if (count($ids) <= 1) {
                // Jahrgang und Geburtstag abgleichen, mit dem gespeicherten Datum des gefundenen Spielers.
                $r = BirthYear::reconcile($data, count($ids) === 1 ? $storedDates[$ids[0]] : null);
                if ($r['invalid'] !== []) {
                    $plan['skipped'][] = ['row' => $no, 'reason' => 'invalid', 'fields' => $r['invalid']];
                    continue;
                }
                $data = $r['changes'];
            }
            // Erst nach der Prüfung vermerken: Ist die erste Zeile ungültig, zählt die nächste gültige.
            if (isset($seen[$key])) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'duplicate_in_file'];
                continue;
            }
            $seen[$key] = true;
            if (count($ids) > 1) {
                $plan['skipped'][] = ['row' => $no, 'reason' => 'ambiguous_match'];
            } elseif (count($ids) === 1) {
                $plan['update'][] = ['id' => $ids[0], 'data' => $data];
            } else {
                $plan['create'][] = $data;
            }
        }
        return $plan;
    }

    /**
     * Spalte JG: Datum (TT.MM.JJJJ oder JJJJ-MM-TT), Excel-Seriennummer oder nur ein Jahrgang.
     * Leitet birth_year ab, wenn es nicht gesetzt ist; ein geleertes JG leert auch den Jahrgang.
     * Ein ungültiges Datum bleibt stehen, damit die Prüfung es ablehnt. Zeitstempel (wie aus
     * JSON.stringify eines Datums) werden nicht gedeutet, weil die Zeitzone den Tag verschiebt.
     * @param array<string, mixed> $out
     */
    private static function birthDate(array &$out): void
    {
        $v = $out['birth_date'];
        if ($v === null) {
            if (!array_key_exists('birth_year', $out)) {
                $out['birth_year'] = null;
            }
            return;
        }
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            return;
        }
        $year = null;
        if (preg_match('/^[1-9]\d{3,4}$/', $v) === 1 && ((int) $v < 1900 || (int) $v > 2100) && (int) $v <= self::MAX_SERIAL) {
            // Excel-Seriennummer: Tage seit dem 30.12.1899 (nur Werte, die kein Jahrgang sein können).
            $date = (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $v . ' days');
            $year = (int) $date->format('Y');
            $out['birth_date'] = $date->format('Y-m-d');
        } elseif (preg_match('/^(19|20)\d{2}$/', $v) === 1) {
            $year = (int) $v;
            $out['birth_date'] = null;
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $year = (int) $m[3];
            $out['birth_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $year = (int) $m[1];
        }
        if ($year !== null && ($out['birth_year'] ?? null) === null) {
            $out['birth_year'] = $year;
        }
    }
}

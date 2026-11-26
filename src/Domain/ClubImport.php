<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Plant den Import der Vereine aus dem nuLiga-Export „Vereine (Stammdaten und Adressen)“, ohne Datenbank.
 * Mitgliedsnummer ist die DBVNr (`08-` plus vierstellige VereinNr). Kürzel und Bundesland setzt der Import
 * nie, Archiv-Vereine werden inaktiv, Kurz- und Druckname werden Aliasnamen. Vereine werden nie gelöscht.
 */
final class ClubImport
{
    /** @return array{member_no: ?string, derived: bool, error: ?string} */
    public static function memberNo(string $vereinNr, ?string $dbvNr): array
    {
        $nr = trim($vereinNr);
        if (preg_match('/^[0-9]{1,4}$/', $nr) !== 1) {
            return ['member_no' => null, 'derived' => false, 'error' => 'bad_verein_nr'];
        }
        $expected = '08-' . str_pad($nr, 4, '0', STR_PAD_LEFT);
        $given = trim((string) $dbvNr);
        if ($given === '') {
            return ['member_no' => $expected, 'derived' => true, 'error' => null];
        }
        if ($given !== $expected) {
            return ['member_no' => null, 'derived' => false, 'error' => 'member_no_mismatch'];
        }
        return ['member_no' => $expected, 'derived' => false, 'error' => null];
    }

    /**
     * @param list<array{verein_nr:string, dbv_nr:?string, name:string, short_name:?string, print_name:?string, status:string, is_federation:bool}> $rows
     * @param list<array{id:int, member_no:?string, name:string, short:?string, state:?string, active:bool, aliases:list<string>}> $existing
     * @return array{create: list<array>, update: list<array>, unchanged: int, skipped: list<array>, warnings: list<array>}
     */
    public static function plan(array $rows, array $existing): array
    {
        $out = ['create' => [], 'update' => [], 'unchanged' => 0, 'skipped' => [], 'warnings' => []];

        $byMemberNo = [];
        /** @var array<string, list<string>> Schlüssel → Besitzer ('c<id>' vorhanden, 'n<row>' neu), für Namen */
        $names = [];
        /** @var array<string, list<string>> Schlüssel → Besitzer, für Kürzel und Aliasnamen */
        $others = [];
        $clubs = [];
        foreach ($existing as $c) {
            $id = (int) $c['id'];
            $clubs[$id] = $c;
            $mn = trim((string) ($c['member_no'] ?? ''));
            if ($mn !== '') {
                $byMemberNo[$mn] = $id;
            }
            self::own($names, (string) $c['name'], 'c' . $id);
            self::own($others, (string) ($c['short'] ?? ''), 'c' . $id);
            foreach ($c['aliases'] ?? [] as $a) {
                self::own($others, (string) $a, 'c' . $id);
            }
        }

        $seen = [];
        $linked = [];
        foreach ($rows as $i => $r) {
            $n = $i + 1;
            if (($r['is_federation'] ?? false) === true) {
                $out['skipped'][] = ['row' => $n, 'reason' => 'federation'];
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                $out['skipped'][] = ['row' => $n, 'reason' => 'missing_name'];
                continue;
            }
            $mn = self::memberNo((string) ($r['verein_nr'] ?? ''), $r['dbv_nr'] ?? null);
            if ($mn['error'] !== null) {
                $out['skipped'][] = ['row' => $n, 'reason' => $mn['error']];
                continue;
            }
            $memberNo = (string) $mn['member_no'];
            if (isset($seen[$memberNo])) {
                $out['skipped'][] = ['row' => $n, 'reason' => 'duplicate_in_file'];
                continue;
            }
            $status = mb_strtolower(trim((string) ($r['status'] ?? '')), 'UTF-8');
            if ($status !== 'aktiv' && $status !== 'archiv') {
                $out['skipped'][] = ['row' => $n, 'reason' => 'bad_status'];
                continue;
            }
            $seen[$memberNo] = true;
            $active = $status === 'aktiv';
            $key = ClubDirectory::normalize($name);
            $warnings = [];

            // Abgleich: Mitgliedsnummer, sonst genau ein Verein ohne Nummer mit diesem Namen oder Alias.
            $id = $byMemberNo[$memberNo] ?? null;
            if ($id === null) {
                $candidates = [];
                foreach ($clubs as $cid => $c) {
                    if (trim((string) ($c['member_no'] ?? '')) !== '' || isset($linked[$cid])) {
                        continue;
                    }
                    $keys = array_map([ClubDirectory::class, 'normalize'], array_merge([(string) $c['name']], $c['aliases'] ?? []));
                    if (in_array($key, $keys, true)) {
                        $candidates[] = $cid;
                    }
                }
                if (count($candidates) > 1) {
                    $out['skipped'][] = ['row' => $n, 'reason' => 'ambiguous_name'];
                    continue;
                }
                if (count($candidates) === 1) {
                    $id = $candidates[0];
                    $warnings[] = ['row' => $n, 'code' => 'linked_by_name', 'detail' => (string) $clubs[$id]['name']];
                }
            }
            $owner = $id !== null ? 'c' . $id : 'n' . $n;

            // Name darf keinem anderen Verein gehören.
            foreach ($names[$key] ?? [] as $o) {
                if ($o !== $owner) {
                    $out['skipped'][] = ['row' => $n, 'reason' => 'duplicate_name'];
                    continue 2;
                }
            }

            $data = [];
            if ($id !== null) {
                $c = $clubs[$id];
                if ($name !== (string) $c['name']) {
                    $data['name'] = $name;
                    $warnings[] = ['row' => $n, 'code' => 'renamed', 'detail' => $c['name'] . ' → ' . $name];
                }
                if ($active !== (bool) $c['active']) {
                    $data['active'] = $active;
                }
                if (trim((string) ($c['member_no'] ?? '')) === '') {
                    $data['member_no'] = $memberNo;
                    $linked[$id] = true;
                }
            }

            // Aliasnamen aus Kurz- und Druckname.
            $ownKeys = [$key];
            if ($id !== null) {
                $ownKeys[] = ClubDirectory::normalize((string) $clubs[$id]['name']);
            }
            $aliases = [];
            foreach (['short_name', 'print_name'] as $field) {
                $cand = trim((string) ($r[$field] ?? ''));
                $ck = ClubDirectory::normalize($cand);
                if ($ck === '' || in_array($ck, $ownKeys, true)) {
                    continue;
                }
                $holders = array_merge($names[$ck] ?? [], $others[$ck] ?? []);
                if (in_array($owner, $holders, true)) {
                    continue;
                }
                if ($holders !== []) {
                    $warnings[] = ['row' => $n, 'code' => 'alias_conflict', 'detail' => $cand];
                    continue;
                }
                $aliases[] = $cand;
                $ownKeys[] = $ck;
            }

            if ($mn['derived']) {
                $warnings[] = ['row' => $n, 'code' => 'member_no_derived', 'detail' => $memberNo];
            }

            // Ab hier gilt die Zeile: Namen und Aliasnamen für die folgenden Zeilen vormerken.
            self::own($names, $name, $owner);
            foreach ($aliases as $a) {
                self::own($others, $a, $owner);
            }
            array_push($out['warnings'], ...$warnings);

            if ($id === null) {
                $out['create'][] = ['row' => $n, 'member_no' => $memberNo, 'name' => $name, 'active' => $active, 'aliases' => $aliases];
            } elseif ($data === [] && $aliases === []) {
                $out['unchanged']++;
            } else {
                $out['update'][] = ['row' => $n, 'id' => $id, 'data' => $data, 'add_aliases' => $aliases];
            }
        }
        return $out;
    }

    /** @param array<string, list<string>> $index */
    private static function own(array &$index, string $text, string $owner): void
    {
        $k = ClubDirectory::normalize($text);
        if ($k === '') {
            return;
        }
        if (!in_array($owner, $index[$k] ?? [], true)) {
            $index[$k][] = $owner;
        }
    }
}

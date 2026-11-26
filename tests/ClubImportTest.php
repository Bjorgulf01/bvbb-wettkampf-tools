<?php
declare(strict_types=1);

use App\Domain\ClubImport;

/** Eine Zeile aus dem (erfundenen) nuLiga-Export; $over ändert einzelne Felder. */
$row = static fn(array $over = []): array => array_merge([
    'verein_nr' => '40',
    'dbv_nr' => '08-0040',
    'name' => 'TSV Musterstadt',
    'short_name' => null,
    'print_name' => null,
    'status' => 'aktiv',
    'is_federation' => false,
], $over);

/** Ein vorhandener (erfundener) Verein; $over ändert einzelne Felder. */
$club = static fn(array $over = []): array => array_merge([
    'id' => 1,
    'member_no' => '08-0040',
    'name' => 'SV Altname',
    'short' => 'SVA',
    'state' => 'BER',
    'active' => true,
    'aliases' => ['Altname'],
], $over);

$codes = static fn(array $warnings): array => array_map(static fn(array $w): string => $w['code'], $warnings);

return [
    'memberNo: vorhandene DBVNr passt zur VereinNr' => function (): void {
        assertSame(['member_no' => '08-0040', 'derived' => false, 'error' => null], ClubImport::memberNo('40', '08-0040'));
    },
    'memberNo: führende Null bleibt erhalten (Review Focus 2)' => function (): void {
        assertSame(['member_no' => '08-0007', 'derived' => true, 'error' => null], ClubImport::memberNo('07', ''));
        assertSame('08-0815', ClubImport::memberNo('0815', null)['member_no']);
        assertSame(true, ClubImport::memberNo('0815', null)['derived']);
        assertSame('08-0007', ClubImport::memberNo(' 07 ', '08-0007')['member_no']);
    },
    'memberNo: abweichende DBVNr ist ein Fehler' => function (): void {
        assertSame(['member_no' => null, 'derived' => false, 'error' => 'member_no_mismatch'], ClubImport::memberNo('40', '08-0041'));
    },
    'memberNo: ungültige VereinNr' => function (): void {
        assertSame('bad_verein_nr', ClubImport::memberNo('4a', null)['error']);
        assertSame('bad_verein_nr', ClubImport::memberNo('12345', null)['error']);
        assertSame('bad_verein_nr', ClubImport::memberNo('', null)['error']);
        assertSame(null, ClubImport::memberNo('4a', null)['member_no']);
    },
    'plan: neuer aktiver und neuer Archiv-Verein' => function () use ($row): void {
        $p = ClubImport::plan([$row(), $row(['verein_nr' => '41', 'dbv_nr' => '08-0041', 'name' => 'SG Grünau Test', 'status' => 'Archiv'])], []);
        assertSame([
            ['row' => 1, 'member_no' => '08-0040', 'name' => 'TSV Musterstadt', 'active' => true, 'aliases' => []],
            ['row' => 2, 'member_no' => '08-0041', 'name' => 'SG Grünau Test', 'active' => false, 'aliases' => []],
        ], $p['create']);
        assertSame([], $p['update']);
        assertSame(0, $p['unchanged']);
        assertSame([], $p['skipped']);
        assertSame([], $p['warnings']);
    },
    'plan: Status ohne Beachtung der Großschreibung' => function () use ($row): void {
        $p = ClubImport::plan([$row(['status' => 'ARCHIV']), $row(['verein_nr' => '41', 'dbv_nr' => null, 'status' => ' Aktiv ', 'name' => 'BC Zwei'])], []);
        assertSame(false, $p['create'][0]['active']);
        assertSame(true, $p['create'][1]['active']);
    },
    'plan: Namensänderung bei bekannter Nummer, Kürzel und Bundesland bleiben' => function () use ($row, $club, $codes): void {
        $p = ClubImport::plan([$row(['name' => 'SV Neuname'])], [$club()]);
        assertSame([['row' => 1, 'id' => 1, 'data' => ['name' => 'SV Neuname'], 'add_aliases' => []]], $p['update']);
        assertSame([['row' => 1, 'code' => 'renamed', 'detail' => 'SV Altname → SV Neuname']], $p['warnings']);
        assertSame(false, array_key_exists('short', $p['update'][0]['data']));
        assertSame(false, array_key_exists('state', $p['update'][0]['data']));
        assertSame([], $p['create']);
    },
    'plan: unveränderter Verein zählt als unverändert' => function () use ($row, $club): void {
        $p = ClubImport::plan([$row(['name' => 'SV Altname'])], [$club()]);
        assertSame(1, $p['unchanged']);
        assertSame([], $p['update']);
        assertSame([], $p['warnings']);
    },
    'plan: Archiv macht einen vorhandenen Verein inaktiv' => function () use ($row, $club): void {
        $p = ClubImport::plan([$row(['name' => 'SV Altname', 'status' => 'Archiv'])], [$club()]);
        assertSame([['row' => 1, 'id' => 1, 'data' => ['active' => false], 'add_aliases' => []]], $p['update']);
    },
    'plan: Verband, fehlender Name, doppelte Nummer' => function () use ($row): void {
        $p = ClubImport::plan([
            $row(['is_federation' => true, 'verein_nr' => '9999', 'dbv_nr' => null]),
            $row(['name' => '', 'verein_nr' => '50', 'dbv_nr' => null]),
            $row(),
            $row(['verein_nr' => '040', 'dbv_nr' => null, 'name' => 'BC Doppelt']),
        ], []);
        assertSame([
            ['row' => 1, 'reason' => 'federation'],
            ['row' => 2, 'reason' => 'missing_name'],
            ['row' => 4, 'reason' => 'duplicate_in_file'],
        ], $p['skipped']);
        assertSame(1, count($p['create']));
    },
    'plan: Fehler aus memberNo und unbekannter Status' => function () use ($row): void {
        $p = ClubImport::plan([
            $row(['dbv_nr' => '08-0041']),
            $row(['verein_nr' => '4a', 'dbv_nr' => null]),
            $row(['verein_nr' => '60', 'dbv_nr' => null, 'status' => 'aufgelöst']),
        ], []);
        assertSame([
            ['row' => 1, 'reason' => 'member_no_mismatch'],
            ['row' => 2, 'reason' => 'bad_verein_nr'],
            ['row' => 3, 'reason' => 'bad_status'],
        ], $p['skipped']);
        assertSame([], $p['create']);
    },
    'plan: Zeile mit falschem Status sperrt die Nummer nicht' => function () use ($row): void {
        $p = ClubImport::plan([$row(['status' => 'aufgelöst']), $row()], []);
        assertSame([['row' => 1, 'reason' => 'bad_status']], $p['skipped']);
        assertSame(2, $p['create'][0]['row']);
    },
    'plan: Verein ohne Nummer wird über den Namen verknüpft' => function () use ($row, $club, $codes): void {
        $existing = [$club(['id' => 7, 'member_no' => null, 'name' => 'BC Beispiel', 'short' => 'BCB', 'state' => 'BRA', 'aliases' => []])];
        $p = ClubImport::plan([$row(['verein_nr' => '55', 'dbv_nr' => '08-0055', 'name' => 'bc beispiel'])], $existing);
        assertSame(7, $p['update'][0]['id']);
        assertSame('08-0055', $p['update'][0]['data']['member_no']);
        assertSame(['linked_by_name', 'renamed'], $codes($p['warnings']));
        assertSame([], $p['create']);
    },
    'plan: Verknüpfung auch über einen Alias' => function () use ($row, $club): void {
        $existing = [$club(['id' => 7, 'member_no' => null, 'name' => 'BC Beispiel 1990', 'aliases' => ['BC Beispiel']])];
        $p = ClubImport::plan([$row(['verein_nr' => '55', 'dbv_nr' => null, 'name' => 'BC Beispiel'])], $existing);
        assertSame(7, $p['update'][0]['id']);
    },
    'plan: mehrere Vereine ohne Nummer mit demselben Namen' => function () use ($row, $club): void {
        $existing = [
            $club(['id' => 7, 'member_no' => null, 'name' => 'BC Beispiel', 'aliases' => []]),
            $club(['id' => 8, 'member_no' => null, 'name' => 'BC Beispiel 2', 'aliases' => ['bc beispiel']]),
        ];
        $p = ClubImport::plan([$row(['verein_nr' => '55', 'dbv_nr' => null, 'name' => 'BC Beispiel'])], $existing);
        assertSame([['row' => 1, 'reason' => 'ambiguous_name']], $p['skipped']);
        assertSame([], $p['update']);
    },
    'plan: neuer Verein mit dem Namen eines anderen Vereins' => function () use ($row, $club): void {
        $p = ClubImport::plan([$row(['verein_nr' => '70', 'dbv_nr' => null, 'name' => 'sv altname'])], [$club()]);
        assertSame([['row' => 1, 'reason' => 'duplicate_name']], $p['skipped']);
        $p2 = ClubImport::plan([$row(), $row(['verein_nr' => '71', 'dbv_nr' => null, 'name' => 'TSV  Musterstadt'])], []);
        assertSame([['row' => 2, 'reason' => 'duplicate_name']], $p2['skipped']);
    },
    'plan: Umbenennung auf den Namen eines anderen Vereins' => function () use ($row, $club): void {
        $existing = [$club(), $club(['id' => 2, 'member_no' => '08-0050', 'name' => 'BC Andere', 'short' => '', 'aliases' => []])];
        $p = ClubImport::plan([$row(['name' => 'BC Andere'])], $existing);
        assertSame([['row' => 1, 'reason' => 'duplicate_name']], $p['skipped']);
        assertSame([], $p['update']);
    },
    'plan: Kurz- und Druckname werden Aliasnamen' => function () use ($row, $club, $codes): void {
        $existing = [$club(), $club(['id' => 2, 'member_no' => '08-0050', 'name' => 'BC Andere', 'short' => '', 'aliases' => ['SVN']])];
        $p = ClubImport::plan([$row(['name' => 'SV Altname', 'short_name' => 'SV Altname (Berlin)', 'print_name' => 'Altn'])], $existing);
        assertSame(['SV Altname (Berlin)', 'Altn'], $p['update'][0]['add_aliases']);
        assertSame([], $p['update'][0]['data']);
        $p2 = ClubImport::plan([$row(['name' => 'SV Altname', 'short_name' => 'sv altname', 'print_name' => 'SVN'])], $existing);
        assertSame(1, $p2['unchanged']);
        assertSame([['row' => 1, 'code' => 'alias_conflict', 'detail' => 'SVN']], $p2['warnings']);
    },
    'plan: vorhandener Alias und eigenes Kürzel werden nicht doppelt angelegt' => function () use ($row, $club): void {
        $p = ClubImport::plan([$row(['name' => 'SV Altname', 'short_name' => ' altname ', 'print_name' => 'SVA'])], [$club()]);
        assertSame(1, $p['unchanged']);
        assertSame([], $p['warnings']);
    },
    'plan: Kurz- und Druckname gleich zählen einmal' => function () use ($row): void {
        $p = ClubImport::plan([$row(['short_name' => 'Musterst.', 'print_name' => 'musterst.'])], []);
        assertSame(['Musterst.'], $p['create'][0]['aliases']);
    },
    'plan: Alias, der einem anderen neuen Verein gehört' => function () use ($row, $codes): void {
        $p = ClubImport::plan([
            $row(['short_name' => 'Muster']),
            $row(['verein_nr' => '41', 'dbv_nr' => null, 'name' => 'BSV Weißensee Test', 'print_name' => 'muster']),
            $row(['verein_nr' => '42', 'dbv_nr' => null, 'name' => 'BC Drei', 'print_name' => 'bsv weissensee test']),
        ], []);
        assertSame(['Muster'], $p['create'][0]['aliases']);
        assertSame([], $p['create'][1]['aliases']);
        assertSame([], $p['create'][2]['aliases']);
        assertSame(['alias_conflict', 'member_no_derived', 'alias_conflict', 'member_no_derived'], $codes($p['warnings']));
    },
    'plan: Alias gleich dem Kürzel eines anderen Vereins' => function () use ($row, $club, $codes): void {
        $p = ClubImport::plan([$row(['verein_nr' => '60', 'dbv_nr' => null, 'name' => 'BC Neu', 'print_name' => 'sva'])], [$club()]);
        assertSame([], $p['create'][0]['aliases']);
        assertSame(['alias_conflict', 'member_no_derived'], $codes($p['warnings']));
    },
    'plan: abgeleitete Nummer ergibt eine Warnung' => function () use ($row): void {
        $p = ClubImport::plan([$row(['verein_nr' => '501', 'dbv_nr' => null])], []);
        assertSame('08-0501', $p['create'][0]['member_no']);
        assertSame([['row' => 1, 'code' => 'member_no_derived', 'detail' => '08-0501']], $p['warnings']);
    },
    'plan: Name gleich Alias oder Kürzel eines anderen Vereins ergibt eine Warnung' => function () use ($row, $club): void {
        $p = ClubImport::plan([
            $row(['verein_nr' => '60', 'dbv_nr' => null, 'name' => 'altname']),
            $row(['verein_nr' => '61', 'dbv_nr' => null, 'name' => 'sva']),
        ], [$club()]);
        assertSame(2, count($p['create']));
        assertSame([
            ['row' => 1, 'code' => 'name_matches_alias', 'detail' => 'SV Altname'],
            ['row' => 1, 'code' => 'member_no_derived', 'detail' => '08-0060'],
            ['row' => 2, 'code' => 'name_matches_alias', 'detail' => 'SV Altname'],
            ['row' => 2, 'code' => 'member_no_derived', 'detail' => '08-0061'],
        ], $p['warnings']);
    },
    'plan: Umbenennung auf einen eigenen Alias ergibt keine Alias-Warnung' => function () use ($row, $club, $codes): void {
        $p = ClubImport::plan([$row(['name' => 'Altname'])], [$club()]);
        assertSame(['renamed'], $codes($p['warnings']));
    },
    'plan: Vereine, die nicht in der Datei stehen, bleiben außen vor' => function () use ($row, $club): void {
        $p = ClubImport::plan([$row(['verein_nr' => '41', 'dbv_nr' => null, 'name' => 'BC Neu'])], [$club()]);
        assertSame([], $p['update']);
        assertSame(0, $p['unchanged']);
        assertSame(1, count($p['create']));
    },
];

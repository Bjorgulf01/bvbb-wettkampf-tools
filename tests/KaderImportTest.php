<?php
declare(strict_types=1);

use App\Domain\KaderImport;

/** Eine gültige Zeile aus der (erfundenen) Kaderliste; $over ändert einzelne Felder. */
$row = static fn(array $over = []): array => array_merge([
    'last_name' => 'Köppen',
    'first_name' => 'Arvid',
    'club_name' => 'BC Potsdam',
    'list_ak' => 'U13',
    'gender' => 'm',
    'roster_status' => 'SK',
], $over);

return [
    'normalizeRow trimmt und macht aus „U 09“ U09' => function (): void {
        $n = KaderImport::normalizeRow(['last_name' => 'Marx ', 'first_name' => 'Jannick ', 'list_ak' => 'U 09']);
        assertSame('Marx', $n['last_name']);
        assertSame('Jannick', $n['first_name']);
        assertSame('U09', $n['list_ak']);
    },
    'normalizeRow: geschützte Leerzeichen am Rand, leere Felder werden null' => function (): void {
        $n = KaderImport::normalizeRow(['last_name' => "\u{00A0}Möllendorf\u{00A0}", 'school' => '  ', 'email2' => '']);
        assertSame('Möllendorf', $n['last_name']);
        assertSame(null, $n['school']);
        assertSame(null, $n['email2']);
    },
    'normalizeRow: birth_year aus birth_date „14.09.2014“' => function (): void {
        $n = KaderImport::normalizeRow(['birth_date' => '14.09.2014']);
        assertSame(2014, $n['birth_year']);
        assertSame('2014-09-14', $n['birth_date']);
    },
    'normalizeRow: Datum im Format JJJJ-MM-TT und einstellige Tage' => function (): void {
        assertSame('2014-09-04', KaderImport::normalizeRow(['birth_date' => '2014-09-04'])['birth_date']);
        assertSame('2014-09-04', KaderImport::normalizeRow(['birth_date' => '4.9.2014'])['birth_date']);
    },
    'normalizeRow: nur eine Jahreszahl in der Spalte JG ergibt den Jahrgang ohne Datum' => function (): void {
        $n = KaderImport::normalizeRow(['birth_date' => '2015']);
        assertSame(2015, $n['birth_year']);
        assertSame(null, $n['birth_date']);
        assertSame(2015, KaderImport::normalizeRow(['birth_date' => 2015])['birth_year']);
    },
    'normalizeRow: ungültiges Datum bleibt stehen (die Prüfung lehnt es ab)' => function (): void {
        $n = KaderImport::normalizeRow(['birth_date' => '31.02.2014']);
        assertSame('31.02.2014', $n['birth_date']);
        assertTrue(!isset($n['birth_year']), 'kein Jahrgang aus einem ungültigen Datum');
        assertSame('2005-11-20T23:00:00.000Z', KaderImport::normalizeRow(['birth_date' => '2005-11-20T23:00:00.000Z'])['birth_date'],
            'Zeitstempel aus JSON.stringify wird nicht geraten (Zeitzone)');
        assertSame(0, KaderImport::normalizeRow(['birth_date' => 0])['birth_date'], 'keine Seriennummer');
    },
    'normalizeRow: ein gesetzter Jahrgang wird nicht überschrieben' => function (): void {
        assertSame(2013, KaderImport::normalizeRow(['birth_year' => '2013', 'birth_date' => '14.09.2014'])['birth_year']);
    },
    'normalizeRow: Status aktiv und passiv' => function (): void {
        assertSame(true, KaderImport::normalizeRow(['active' => 'aktiv'])['active']);
        assertSame(false, KaderImport::normalizeRow(['active' => ' Passiv '])['active']);
        assertSame(false, KaderImport::normalizeRow(['active' => false])['active']);
        assertTrue(!array_key_exists('active', KaderImport::normalizeRow(['active' => ''])), 'leerer Status ändert nichts');
        assertSame('vielleicht', KaderImport::normalizeRow(['active' => 'vielleicht'])['active'], 'ungültig, wird abgelehnt');
    },
    'normalizeRow: ganze Zahlen aus Excel als Zahl' => function (): void {
        assertSame(12, KaderImport::normalizeRow(['list_no' => 12.0])['list_no']);
        assertSame(12, KaderImport::normalizeRow(['list_no' => ' 12 '])['list_no']);
    },
    'normalizeRow: Zahlen in Textfeldern werden Text (Telefon, T-Shirt-Größe aus Excel)' => function (): void {
        $n = KaderImport::normalizeRow(['shirt_size' => 164, 'phone_player' => 5550123.0, 'list_ak' => 'U13']);
        assertSame('164', $n['shirt_size']);
        assertSame('5550123', $n['phone_player']);
    },
    'normalizeRow: Excel-Seriennummer in JG ergibt Datum und Jahrgang' => function (): void {
        $n = KaderImport::normalizeRow(['birth_date' => 38676]);
        assertSame('2005-11-20', $n['birth_date'], '30.12.1899 plus 38676 Tage');
        assertSame(2005, $n['birth_year']);
        assertSame('2014-09-14', KaderImport::normalizeRow(['birth_date' => 41896.0])['birth_date']);
        assertSame('2014-09-14', KaderImport::normalizeRow(['birth_date' => '41896'])['birth_date'], 'auch als Text');
    },
    'normalizeRow: geleertes JG leert auch den Jahrgang' => function (): void {
        $n = KaderImport::normalizeRow(['birth_date' => '']);
        assertSame(null, $n['birth_date']);
        assertTrue(array_key_exists('birth_year', $n) && $n['birth_year'] === null, 'birth_year wird geleert');
    },
    'plan: völlig leere Zeilen werden still übergangen' => function () use ($row): void {
        $p = KaderImport::plan([['last_name' => '', 'first_name' => ' ', 'email1' => null], $row()], []);
        assertSame([], $p['skipped']);
        assertSame(1, count($p['create']));
    },
    'plan: ist die erste von zwei gleichen Zeilen ungültig, zählt die zweite' => function () use ($row): void {
        $p = KaderImport::plan([$row(['list_no' => 'zwölf']), $row(['list_no' => 4])], []);
        assertSame([['row' => 1, 'reason' => 'invalid', 'fields' => ['list_no']]], $p['skipped']);
        assertSame(4, $p['create'][0]['list_no']);
    },
    'plan: neue Spieler werden angelegt, bekannte geändert (Abgleich ohne Groß-/Kleinschreibung)' => function () use ($row): void {
        $p = KaderImport::plan([
            $row(),
            $row(['last_name' => 'müßig', 'first_name' => 'LOTTA ']),
        ], [['id' => 5, 'last_name' => 'Müßig', 'first_name' => 'Lotta']]);
        assertSame(1, count($p['create']));
        assertSame('Köppen', $p['create'][0]['last_name']);
        assertSame(5, $p['update'][0]['id']);
        assertSame('müßig', $p['update'][0]['data']['last_name'], 'Schreibweise der Datei wird übernommen');
        assertSame([], $p['skipped']);
    },
    'plan: Platzhalterzeile (m/w = O22) und Zeile ohne Nachnamen werden übersprungen' => function () use ($row): void {
        $p = KaderImport::plan([
            $row(['last_name' => 'Musterfrau', 'first_name' => 'Platzhalter', 'gender' => 'O22']),
            $row(['last_name' => '  ']),
            $row(['last_name' => 'Legende: LK = Landeskader']),
            $row(['first_name' => null]),
            $row(),
        ], []);
        assertSame([
            ['row' => 1, 'reason' => 'placeholder'],
            ['row' => 2, 'reason' => 'missing_last_name'],
            ['row' => 3, 'reason' => 'placeholder'],
            ['row' => 4, 'reason' => 'missing_first_name'],
        ], $p['skipped']);
        assertSame(1, count($p['create']));
    },
    'plan: zwei gleiche Namen in der Datei, die erste zählt' => function () use ($row): void {
        $p = KaderImport::plan([
            $row(['last_name' => 'Lübbe', 'list_no' => 37]),
            $row(['last_name' => 'Lübbe ', 'first_name' => 'arvid', 'list_no' => 38]),
        ], []);
        assertSame(1, count($p['create']));
        assertSame(37, $p['create'][0]['list_no']);
        assertSame([['row' => 2, 'reason' => 'duplicate_in_file']], $p['skipped']);
    },
    'plan: ungültige Werte überspringen die Zeile mit den Feldnamen' => function () use ($row): void {
        $p = KaderImport::plan([$row(['birth_date' => '31.02.2014', 'list_no' => 'zwölf'])], []);
        assertSame([], $p['create']);
        assertSame([['row' => 1, 'reason' => 'invalid', 'fields' => ['list_no', 'birth_date']]], $p['skipped']);
    },
    'plan: Name in der Datenbank doppelt, Zeile wird nicht zugeordnet' => function () use ($row): void {
        $p = KaderImport::plan([$row()], [
            ['id' => 1, 'last_name' => 'Köppen', 'first_name' => 'Arvid'],
            ['id' => 2, 'last_name' => 'KÖPPEN', 'first_name' => 'Arvid'],
        ]);
        assertSame([], $p['create']);
        assertSame([], $p['update']);
        assertSame([['row' => 1, 'reason' => 'ambiguous_match']], $p['skipped']);
    },
    'plan: Zeile, die keine Liste von Feldern ist, wird übersprungen' => function () use ($row): void {
        $p = KaderImport::plan(['Köppen', $row()], []);
        assertSame([['row' => 1, 'reason' => 'invalid']], $p['skipped']);
        assertSame(1, count($p['create']));
    },
];

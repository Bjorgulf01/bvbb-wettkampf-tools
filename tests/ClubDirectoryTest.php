<?php
declare(strict_types=1);

use App\Domain\ClubDirectory;

/** Erfundene Vereine (Testdaten). */
$sampleClubs = static fn(): ClubDirectory => new ClubDirectory([
        ['id' => 1, 'member_no' => '01-001', 'name' => 'SV Berliner Brauereien', 'short' => 'SVBB', 'state' => 'BER', 'aliases' => ['SV Berl. Brauereien']],
        ['id' => 2, 'member_no' => '01-002', 'name' => 'TSV-Spandau', 'short' => 'TSV Sp', 'state' => 'BER', 'aliases' => ['TSV Spandau 1860']],
        ['id' => 3, 'member_no' => '02-001', 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BRA', 'aliases' => []],
        ['id' => 4, 'member_no' => null, 'name' => 'SG Grün-Weiß Bärenstadt', 'short' => '', 'state' => null, 'aliases' => []],
]);

return [
    'Treffer über den Langnamen' => function () use ($sampleClubs): void {
        assertSame([
            'club_id' => 1, 'member_no' => '01-001', 'name' => 'SV Berliner Brauereien',
            'short' => 'SVBB', 'state' => 'BER', 'known' => true,
        ], $sampleClubs()->lookup('SV Berliner Brauereien'));
    },
    'Groß- und Kleinschreibung und Leerzeichen am Rand sind egal' => function () use ($sampleClubs): void {
        $r = $sampleClubs()->lookup('  tsv-spandau ');
        assertSame('TSV Sp', $r['short']);
        assertSame(2, $r['club_id']);
        assertSame('TSV-Spandau', $r['name'], 'offizieller Name, nicht die Eingabe');
        assertSame(3, $sampleClubs()->lookup("BC Potsdam\u{00A0}")['club_id'], 'geschütztes Leerzeichen am Rand');
        assertSame(4, $sampleClubs()->lookup('sg grün-weiß bärenstadt')['club_id'], 'Umlaute ohne Großschreibung');
    },
    'Bundesland kommt vom Verein' => function () use ($sampleClubs): void {
        assertSame('BRA', $sampleClubs()->lookup('BC Potsdam')['state']);
        assertSame('BER', $sampleClubs()->lookup('SVBB')['state']);
    },
    'Treffer über Kürzel und Alias' => function () use ($sampleClubs): void {
        assertSame(1, $sampleClubs()->lookup('svbb')['club_id']);
        assertSame(2, $sampleClubs()->lookup('TSV Spandau 1860')['club_id']);
        assertSame(1, $sampleClubs()->lookup(' sv berl. brauereien')['club_id']);
    },
    'Verein ohne Bundesland und Mitgliedsnummer' => function () use ($sampleClubs): void {
        $r = $sampleClubs()->lookup('SG Grün-Weiß Bärenstadt');
        assertSame(true, $r['known']);
        assertSame('', $r['state']);
        assertSame(null, $r['member_no']);
        assertSame('', $r['short']);
    },
    'unbekannter Verein bekommt kein erfundenes Kürzel' => function () use ($sampleClubs): void {
        assertSame([
            'club_id' => null, 'member_no' => null, 'name' => 'Neuer Verein e.V.',
            'short' => '', 'state' => '', 'known' => false,
        ], $sampleClubs()->lookup(' Neuer Verein e.V. '));
    },
    'leere Eingabe' => function () use ($sampleClubs): void {
        $r = $sampleClubs()->lookup('');
        assertSame('', $r['short']);
        assertSame(false, $r['known']);
        assertSame(false, $sampleClubs()->lookup('   ')['known']);
        assertSame(false, (new ClubDirectory([]))->lookup('BC Potsdam')['known']);
    },
    'Langname hat Vorrang vor Kürzel und Alias anderer Vereine' => function () use ($sampleClubs): void {
        $d = new ClubDirectory([
            ['id' => 1, 'member_no' => '1', 'name' => 'BCP', 'short' => 'X', 'state' => 'BER', 'aliases' => []],
            ['id' => 2, 'member_no' => '2', 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BRA', 'aliases' => []],
        ]);
        assertSame(1, $d->lookup('bcp')['club_id']);
    },
    'mehrdeutiger Text ergibt keinen Treffer' => function () use ($sampleClubs): void {
        $d = new ClubDirectory([
            ['id' => 1, 'member_no' => '1', 'name' => 'SC Brandenburg', 'short' => 'SCB', 'state' => 'BRA', 'aliases' => []],
            ['id' => 2, 'member_no' => '2', 'name' => 'Sportclub Berlin', 'short' => 'SCB', 'state' => 'BER', 'aliases' => []],
        ]);
        $r = $d->lookup('SCB');
        assertSame(false, $r['known'], 'zwei Vereine mit gleichem Kürzel');
        assertSame(null, $r['club_id']);
        assertSame(1, $d->lookup('SC Brandenburg')['club_id'], 'Langname bleibt eindeutig');
    },
    'eindeutiger Alias löst gleiches Kürzel zweier Vereine auf' => function (): void {
        $d = new ClubDirectory([
            ['id' => 1, 'member_no' => '1', 'name' => 'SC Brandenburg', 'short' => 'SCB', 'state' => 'BRA', 'aliases' => ['scb']],
            ['id' => 2, 'member_no' => '2', 'name' => 'Sportclub Berlin', 'short' => 'SCB', 'state' => 'BER', 'aliases' => []],
        ]);
        assertSame(1, $d->lookup('SCB')['club_id']);
    },
    'ß und Umlaute in Großschreibung' => function () use ($sampleClubs): void {
        assertSame(4, $sampleClubs()->lookup('SG GRÜN-WEISS BÄRENSTADT')['club_id'], 'SS statt ß');
        assertSame(4, $sampleClubs()->lookup('SG GRÜN-WEIß BÄRENSTADT')['club_id']);
    },
    'Leerraum im Namen wird vereinheitlicht' => function () use ($sampleClubs): void {
        assertSame(1, $sampleClubs()->lookup("SV\u{00A0}Berliner  Brauereien")['club_id'], 'geschütztes und doppeltes Leerzeichen');
        assertSame(1, $sampleClubs()->lookup("\u{200B}SV Berliner Brauereien")['club_id'], 'Null-Breite-Leerzeichen am Rand');
    },
    'Bundesland außer BER und BRA wird leer' => function (): void {
        $d = new ClubDirectory([
            ['id' => 1, 'member_no' => '1', 'name' => 'A', 'short' => '', 'state' => 'ber', 'aliases' => []],
            ['id' => 2, 'member_no' => '2', 'name' => 'B', 'short' => '', 'state' => 'Berlin', 'aliases' => []],
        ]);
        assertSame('', $d->lookup('A')['state']);
        assertSame('', $d->lookup('B')['state']);
    },
    'doppelte ID wird abgelehnt' => function (): void {
        assertThrows(fn() => new ClubDirectory([
            ['id' => 5, 'member_no' => null, 'name' => 'BC Alt', 'short' => '', 'state' => 'BER', 'aliases' => []],
            ['id' => 5, 'member_no' => null, 'name' => 'BC Neu', 'short' => '', 'state' => 'BRA', 'aliases' => []],
        ]), InvalidArgumentException::class);
    },
    'Zahlen als Kürzel oder Alias' => function (): void {
        $d = new ClubDirectory([
            ['id' => 1, 'member_no' => 4711, 'name' => 'TSV 1860', 'short' => 1860, 'state' => 'BER', 'aliases' => [1860]],
        ]);
        assertSame(1, $d->lookup('1860')['club_id']);
        assertSame('4711', $d->lookup('1860')['member_no']);
    },
];

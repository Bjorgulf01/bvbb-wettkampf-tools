<?php
declare(strict_types=1);

use App\Domain\ClubDirectory;
use App\Domain\PlayerValidator;

/** Erfundene Vereine (Testdaten). */
$clubs = static fn(): ClubDirectory => new ClubDirectory([
    ['id' => 1, 'member_no' => '01-001', 'name' => 'SV Berliner Brauereien', 'short' => 'SVBB', 'state' => 'BER', 'aliases' => ['SV Berl. Brauereien']],
    ['id' => 2, 'member_no' => '02-001', 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BRA', 'aliases' => []],
]);

/** Ein Spieler ohne Befund im Jahr 2026; $over ändert einzelne Felder. */
$player = static fn(array $over = []): array => array_merge([
    'id' => null,
    'last_name' => 'Müßig',
    'first_name' => 'Lotta',
    'club_name' => 'SV Berliner Brauereien',
    'list_ak' => 'U19',
    'gender' => 'w',
    'birth_year' => 2008,
    'roster_status' => 'LK',
    'training_site' => 'LLZ',
], $over);

$codes = static fn(array $issues): array => array_column($issues, 'code');

return [
    'Spieler ohne Befund ergibt keine Meldung' => function () use ($clubs, $player): void {
        assertSame([], PlayerValidator::issues([$player()], 2026, $clubs()));
    },
    'leere Liste ergibt keine Meldung' => function () use ($clubs): void {
        assertSame([], PlayerValidator::issues([], 2026, $clubs()));
    },
    'Befund-Codes ohne ak_mismatch und bad_list_ak, in fester Reihenfolge' => function (): void {
        assertSame(['missing_birth_year', 'bad_birth_year', 'missing_club', 'unknown_club', 'bad_gender',
            'missing_roster_status', 'training_site_looks_like_ak', 'duplicate_name'], PlayerValidator::CODES);
    },
    'kein ak_mismatch mehr: Jahrgang 2015, Liste U11, Jahr 2026' => function () use ($clubs, $player): void {
        assertSame([], PlayerValidator::issues([$player(['last_name' => 'Jäckel', 'first_name' => 'Gustav',
            'birth_year' => 2015, 'list_ak' => 'U11'])], 2026, $clubs()));
    },
    'kein bad_list_ak mehr: U10 in der Liste' => function () use ($clubs, $player): void {
        assertSame([], PlayerValidator::issues([$player(['list_ak' => 'U10'])], 2026, $clubs()));
    },
    'missing_birth_year' => function () use ($clubs, $player, $codes): void {
        $issues = PlayerValidator::issues([$player(['birth_year' => null])], 2026, $clubs());
        assertSame(['missing_birth_year'], $codes($issues));
        assertSame('Müßig, Lotta', $issues[0]['player']);
    },
    'bad_birth_year: Jahrgang nach dem Jahr der Prüfung' => function () use ($clubs, $player, $codes): void {
        $issues = PlayerValidator::issues([$player(['birth_year' => 2027])], 2026, $clubs());
        assertSame(['bad_birth_year'], $codes($issues));
        assertSame('Jahrgang 2027', $issues[0]['detail']);
    },
    'missing_club: leerer Vereinstext' => function () use ($clubs, $player, $codes): void {
        assertSame(['missing_club'], $codes(PlayerValidator::issues([$player(['club_name' => ''])], 2026, $clubs())));
        assertSame(['missing_club'], $codes(PlayerValidator::issues([$player(['club_name' => null])], 2026, $clubs())));
        assertSame(['missing_club'], $codes(PlayerValidator::issues([$player(['club_name' => "  \u{00A0}"])], 2026, $clubs())));
    },
    'unknown_club: Verein nicht im Verzeichnis, Detail nennt den Text' => function () use ($clubs, $player): void {
        $issues = PlayerValidator::issues([$player(['club_name' => 'Neuer Verein e.V.'])], 2026, $clubs());
        assertSame([['code' => 'unknown_club', 'id' => null, 'player' => 'Müßig, Lotta', 'detail' => 'Neuer Verein e.V.']], $issues);
    },
    'unknown_club: Alias und andere Schreibweise werden gefunden' => function () use ($clubs, $player): void {
        assertSame([], PlayerValidator::issues([
            $player(['club_name' => 'SV Berl. Brauereien']),
            $player(['first_name' => 'Jette', 'club_name' => 'bc potsdam ']),
        ], 2026, $clubs()));
    },
    'bad_gender: leer und ungültig' => function () use ($clubs, $player, $codes): void {
        $issues = PlayerValidator::issues([
            $player(['first_name' => 'Yara', 'gender' => null]),
            $player(['first_name' => 'Finn', 'gender' => 'x']),
            $player(['first_name' => 'Jonte', 'gender' => 'm']),
        ], 2026, $clubs());
        assertSame(['bad_gender', 'bad_gender'], $codes($issues));
        assertSame(['Müßig, Yara', 'Müßig, Finn'], array_column($issues, 'player'));
        assertSame('x', $issues[1]['detail']);
    },
    'missing_roster_status, auch bei passiven Spielern' => function () use ($clubs, $player, $codes): void {
        assertSame(['missing_roster_status'], $codes(PlayerValidator::issues(
            [$player(['roster_status' => null, 'active' => false])], 2026, $clubs())));
    },
    'training_site_looks_like_ak' => function () use ($clubs, $player, $codes): void {
        $issues = PlayerValidator::issues([$player(['training_site' => 'U 13'])], 2026, $clubs());
        assertSame(['training_site_looks_like_ak'], $codes($issues));
        assertSame('U 13', $issues[0]['detail']);
        assertSame([], PlayerValidator::issues([$player(['training_site' => 'Uckermark'])], 2026, $clubs()));
    },
    'duplicate_name: beide Einträge, ohne Groß-/Kleinschreibung und Leerraum am Rand' => function () use ($clubs, $player): void {
        $issues = PlayerValidator::issues([
            $player(['id' => 7, 'last_name' => 'Lübbe', 'first_name' => 'Arvid']),
            $player(['id' => 8, 'first_name' => 'Jette']),
            $player(['id' => 9, 'last_name' => 'LÜBBE ', 'first_name' => 'arvid']),
        ], 2026, $clubs());
        assertSame(['duplicate_name', 'duplicate_name'], array_column($issues, 'code'));
        assertSame([7, 9], array_column($issues, 'id'));
    },
    'mehrere Befunde: geordnet nach Code, dann nach Reihenfolge der Spieler' => function () use ($clubs, $player, $codes): void {
        $issues = PlayerValidator::issues([
            $player(['first_name' => 'A', 'gender' => null, 'birth_year' => null]),
            $player(['first_name' => 'B', 'birth_year' => null]),
        ], 2026, $clubs());
        assertSame(['missing_birth_year', 'missing_birth_year', 'bad_gender'], $codes($issues));
        assertSame(['Müßig, A', 'Müßig, B', 'Müßig, A'], array_column($issues, 'player'));
    },
    'fehlende Schlüssel ergeben keinen Fehler, nur Befunde' => function () use ($clubs): void {
        $issues = PlayerValidator::issues([['last_name' => 'Köppen', 'first_name' => 'Arvid']], 2026, $clubs());
        assertSame(['missing_birth_year', 'missing_club', 'bad_gender', 'missing_roster_status'], array_column($issues, 'code'));
    },
];

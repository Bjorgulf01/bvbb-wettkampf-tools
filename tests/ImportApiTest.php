<?php
declare(strict_types=1);

use App\Api\AuthController;
use App\Api\ClubsController;
use App\Api\PlayersController;
use App\Auth\AuthGuard;
use App\Auth\Session;
use App\Auth\TestAuthProvider;
use App\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Migrator;
use App\Rbac\Permissions;
use App\Repo\UserRepo;

require_once __DIR__ . '/support/db.php';

/** Stichtag der Tests: 16.11.2026. */
const IMPORT_NOW = 1794787200;

/**
 * Testumgebung: migrierte Datenbank mit erfundenen Vereinen und Spielern.
 * as(person) liefert [Router, CSRF-Token] für eine angemeldete Person.
 */
$setup = static function (): object {
    $pdo = testPdo();
    (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
    $pdo->exec("INSERT INTO clubs (id, member_no, name, short, state) VALUES
        (1, '01-001', 'SV Berliner Brauereien', 'SVBB', 'BER'),
        (2, '02-001', 'BC Potsdam', 'BCP', 'BRA'),
        (3, NULL, 'TSV-Spandau', 'TSV Sp', 'BER')");
    $pdo->exec("INSERT INTO club_aliases (club_id, alias) VALUES (1, 'SV Berl. Brauereien'), (3, 'TSV Spandau 1860')");
    $pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, list_no, list_ak, gender, birth_year, roster_status, active, training_site) VALUES
        (1, 'Müßig', 'Lotta', 1, 'SV Berliner Brauereien', 1, 'U19', 'w', 2008, 'LK', 1, 'LLZ'),
        (2, 'Köppen', 'Arvid', 2, 'BC Potsdam', 2, 'U13', 'm', 2015, 'SK', 1, 'BSP'),
        (3, 'Weißkopf', 'Ivo', NULL, 'Neuer Verein e.V.', 3, 'U09', 'm', NULL, 'LK', 0, 'SLZB')");
    $pdo->exec("INSERT INTO player_private (player_id, email1) VALUES (1, 'lotta@example.org'), (2, 'arvid@example.org')");

    return new class ($pdo) {
        public function __construct(public PDO $pdo)
        {
        }

        public function as(string $person): array
        {
            $store = [];
            $clock = static fn(): int => IMPORT_NOW;
            $cfg = new Config(['env' => 'development', 'test_auth' => true]);
            $session = new Session($store, static function (): void {
            });
            $users = new UserRepo($this->pdo);
            $guard = new AuthGuard($session, $users, 3600, $clock);
            $router = new Router($guard, static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            });
            (new AuthController($session, $users, $guard, new TestAuthProvider(TestAuthProvider::defaultPersonas()), $cfg, $clock))->routes($router);
            $permissions = Permissions::fromDb($this->pdo);
            (new PlayersController($this->pdo, $permissions, $guard, $clock))->routes($router);
            (new ClubsController($this->pdo, $permissions, $guard))->routes($router);
            $res = $router->dispatch(Request::fromParts('POST', '/api/auth/test-login', [], ['Content-Type' => 'application/json'], json_encode(['person' => $person])));
            if ($res->status !== 200) {
                throw new RuntimeException('Anmeldung fehlgeschlagen: ' . $person);
            }
            return [$router, $res->body['csrf']];
        }

        public function count(string $sql): int
        {
            return (int) $this->pdo->query($sql)->fetchColumn();
        }

        /** Alle Spieler und privaten Daten als Vergleichswert. */
        public function snapshot(): string
        {
            return json_encode([
                $this->pdo->query('SELECT * FROM players ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT * FROM player_private ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
            ]);
        }
    };
};

$send = static function (Router $router, string $token, string $method, string $path, $body): Response {
    return $router->dispatch(Request::fromParts($method, $path, [], [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
    ], json_encode($body)));
};

$get = static fn(Router $router, string $path, array $query = []): Response =>
    $router->dispatch(new Request('GET', $path, $query));

/** Datei mit Leerzeichen am Rand, doppeltem Namen, unbekanntem Verein, Platzhalterzeile (Review-Fokus 4). */
$file = static fn(): array => [
    ['last_name' => 'Marx ', 'first_name' => 'Jannick ', 'club_name' => 'SV Berl. Brauereien', 'list_ak' => 'U 09',
        'gender' => 'm', 'birth_date' => '14.09.2018', 'roster_status' => 'LK', 'active' => 'aktiv', 'email1' => 'marx@example.org'],
    ['last_name' => 'köppen', 'first_name' => 'Arvid', 'club_name' => 'BC Potsdam', 'list_ak' => 'U13',
        'gender' => 'm', 'birth_date' => '2015', 'roster_status' => 'LK', 'active' => 'aktiv'],
    ['last_name' => 'Lindemann', 'first_name' => 'Yara', 'club_name' => 'Neuer Verein e.V.', 'list_ak' => 'U15',
        'gender' => 'w', 'birth_date' => '03.05.2012', 'roster_status' => 'SK', 'active' => 'passiv'],
    ['last_name' => 'Marx', 'first_name' => 'jannick', 'club_name' => 'BC Potsdam', 'gender' => 'm'],
    ['last_name' => 'Musterfrau', 'first_name' => 'Platzhalter', 'gender' => 'O22'],
];

$codesFor = static fn(array $issues, string $player): array =>
    array_values(array_map(static fn(array $i): string => $i['code'],
        array_filter($issues, static fn(array $i): bool => $i['player'] === $player)));

return [
    'Vorschau (dry_run): Zählung, übersprungene Zeilen und Befunde, kein Absturz' => function () use ($setup, $send, $file, $codesFor): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => true, 'year' => 2026]);
        assertSame(200, $res->status);
        assertSame(2, $res->body['create'], 'Marx und Lindemann');
        assertSame(1, $res->body['update'], 'Köppen');
        assertSame([['row' => 4, 'reason' => 'duplicate_in_file'], ['row' => 5, 'reason' => 'placeholder']], $res->body['skipped']);
        assertSame(['unknown_club'], $codesFor($res->body['issues'], 'Lindemann, Yara'));
        assertSame([], $codesFor($res->body['issues'], 'Marx, Jannick'), 'getrimmt, U09 passt zu 2018');
    },
    'dry_run verändert die Datenbank nicht' => function () use ($setup, $send, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => true]);
        assertSame($before, $env->snapshot());
    },
    'apply: neue Spieler danach im GET, Verein über Alias zugeordnet, unbekannter Verein ohne club_id' => function () use ($setup, $send, $get, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        assertSame(200, $res->status);
        $rows = $get($router, '/api/players', ['year' => '2026'])->body['players'];
        $byName = array_column($rows, null, 'last_name');
        assertSame(5, count($rows));
        assertSame('Jannick', $byName['Marx']['first_name']);
        assertSame('U09', $byName['Marx']['list_ak']);
        assertSame(2018, $byName['Marx']['birth_year']);
        assertSame('2018-09-14', $byName['Marx']['birth_date']);
        assertSame('SVBB', $byName['Marx']['club_short']);
        assertSame('BER', $byName['Marx']['state']);
        assertSame('marx@example.org', $byName['Marx']['email1']);
        assertSame(false, $byName['Lindemann']['active']);
        assertSame('', $byName['Lindemann']['club_short']);
        assertSame(null, $env->pdo->query("SELECT club_id FROM players WHERE last_name = 'Lindemann'")->fetchColumn() ?: null);
    },
    'apply: bekannter Spieler wird geändert, fehlende Spalten und andere Spieler bleiben unverändert' => function () use ($setup, $send, $get, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        $byId = array_column($get($router, '/api/players')->body['players'], null, 'id');
        assertSame('köppen', $byId[2]['last_name'], 'Schreibweise der Datei');
        assertSame('LK', $byId[2]['roster_status']);
        assertSame('arvid@example.org', $byId[2]['email1'], 'Spalte nicht in der Datei: unverändert');
        assertSame('BSP', $byId[2]['training_site']);
        assertSame('Müßig', $byId[1]['last_name'], 'nicht in der Datei: unverändert');
        assertSame('lotta@example.org', $byId[1]['email1']);
        assertSame(5, count($byId), 'niemand gelöscht');
    },
    'apply: Protokoll je Spieler mit Feldnamen, ohne Werte' => function () use ($setup, $send, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        $log = $env->pdo->query("SELECT action, object_id, summary FROM audit_log WHERE object_type = 'player' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        assertSame(['import_update', 'import_create', 'import_create'], array_column($log, 'action'));
        assertSame('2', (string) $log[0]['object_id']);
        assertTrue(str_contains($log[0]['summary'], 'roster_status'), $log[0]['summary']);
        foreach ($log as $l) {
            assertTrue(!str_contains($l['summary'], '@') && !str_contains($l['summary'], 'Marx'), $l['summary']);
        }
    },
    'zweiter gleicher Import ändert nichts und protokolliert nichts' => function () use ($setup, $send, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        $before = $env->snapshot();
        $res = $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        assertSame(0, $res->body['create']);
        assertSame(3, $res->body['update']);
        assertSame($before, $env->snapshot());
    },
    'coach ruft den Import auf: 403, nichts gespeichert' => function () use ($setup, $send, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('trainer');
        $before = $env->snapshot();
        $res = $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => false]);
        assertSame(403, $res->status);
        assertSame($before, $env->snapshot());
    },
    'Person ohne Rolle und NADA ohne Schreibrecht: 403' => function () use ($setup, $send, $file): void {
        foreach (['ohne-rolle', 'nada', 'pruefer', 'basis'] as $person) {
            [$router, $token] = $setup()->as($person);
            assertSame(403, $send($router, $token, 'POST', '/api/players/import', ['rows' => $file(), 'dry_run' => true])->status, $person);
        }
    },
    'Import ohne CSRF-Token: 403' => function () use ($setup, $file): void {
        [$router] = $setup()->as('kaderpflege');
        $res = $router->dispatch(Request::fromParts('POST', '/api/players/import', [], ['Content-Type' => 'application/json'],
            json_encode(['rows' => $file(), 'dry_run' => true])));
        assertSame(403, $res->status);
    },
    'unbekanntes oder nur lesbares Feld in einer Zeile: 403 mit Feldnamen, nichts gespeichert' => function () use ($setup, $send, $file): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $rows = $file();
        $rows[1]['club_id'] = 3;
        $rows[2]['state'] = 'BRA';
        $rows[2]['alter'] = 14;
        $res = $send($router, $token, 'POST', '/api/players/import', ['rows' => $rows, 'dry_run' => false]);
        assertSame(403, $res->status);
        assertSame(['error' => 'forbidden', 'fields' => ['club_id', 'state', 'alter']], $res->body);
        assertSame($before, $env->snapshot());
    },
    'ungültiger Body: 422 invalid_body' => function () use ($setup, $send, $file): void {
        [$router, $token] = $setup()->as('kaderpflege');
        foreach ([
            ['dry_run' => true],
            ['rows' => 'Köppen', 'dry_run' => true],
            ['rows' => ['a' => []], 'dry_run' => true],
            ['rows' => $file(), 'dry_run' => 'ja'],
            ['rows' => $file()],
            ['rows' => $file(), 'dry_run' => true, 'year' => 26],
            ['rows' => $file(), 'dry_run' => true, 'replace' => true],
        ] as $i => $body) {
            $res = $send($router, $token, 'POST', '/api/players/import', $body);
            assertSame(422, $res->status, 'Fall ' . $i);
        }
    },
    'ungültige Zeile wird übersprungen, der Rest importiert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'POST', '/api/players/import', ['dry_run' => false, 'rows' => [
            ['last_name' => 'Rademacher', 'first_name' => 'Henri', 'birth_date' => '31.02.2014'],
            ['last_name' => 'Rühmann', 'first_name' => 'Klara', 'club_name' => 'TSV Spandau 1860'],
            'kein Objekt',
        ]]);
        assertSame(200, $res->status);
        assertSame([['row' => 1, 'reason' => 'invalid', 'fields' => ['birth_date']], ['row' => 3, 'reason' => 'invalid']], $res->body['skipped']);
        assertSame(1, $res->body['create']);
        assertSame('3', (string) $env->pdo->query("SELECT club_id FROM players WHERE last_name = 'Rühmann'")->fetchColumn(), 'Alias');
    },
    'Datenprüfung: Kaderpflege bekommt die Befunde aus der Datenbank' => function () use ($setup, $get): void {
        [$router] = $setup()->as('kaderpflege');
        $res = $get($router, '/api/players/issues', ['year' => '2026']);
        assertSame(200, $res->status);
        $weisskopf = array_values(array_filter($res->body['issues'], static fn(array $i): bool => $i['id'] === 3));
        assertSame(['missing_birth_year', 'unknown_club'], array_column($weisskopf, 'code'), 'passive Spieler werden mitgeprüft');
        assertSame('Weißkopf, Ivo', $weisskopf[0]['player']);
        assertSame('Neuer Verein e.V.', $weisskopf[1]['detail']);
        assertSame([], array_values(array_filter($res->body['issues'], static fn(array $i): bool => $i['id'] !== 3)));
    },
    'Datenprüfung: inaktiver Verein gilt als unbekannt' => function () use ($setup, $get): void {
        $env = $setup();
        $env->pdo->exec('UPDATE clubs SET active = 0 WHERE id = 2');
        [$router] = $env->as('admin');
        $issues = $get($router, '/api/players/issues', ['year' => '2026'])->body['issues'];
        assertSame(['unknown_club'], array_column(array_filter($issues, static fn(array $i): bool => $i['id'] === 2), 'code'));
    },
    'Datenprüfung: nur Kaderpflege und Admin, ungültiges Jahr 422' => function () use ($setup, $get): void {
        $env = $setup();
        foreach (['trainer', 'nada', 'pruefer', 'basis', 'ohne-rolle'] as $person) {
            [$router] = $env->as($person);
            assertSame(403, $get($router, '/api/players/issues')->status, $person);
        }
        [$router] = $env->as('trainer-kaderpflege');
        assertSame(200, $get($router, '/api/players/issues')->status, 'zwei Rollen: Vereinigung');
        assertSame(422, $get($router, '/api/players/issues', ['year' => 'x'])->status);
    },
    'Vereine: alle Rollen lesen die Liste mit Aliasnamen, auch inaktive Vereine' => function () use ($setup, $get): void {
        $env = $setup();
        $env->pdo->exec('UPDATE clubs SET active = 0 WHERE id = 3');
        [$router] = $env->as('basis');
        $res = $get($router, '/api/clubs');
        assertSame(200, $res->status);
        assertSame([
            'id' => 2, 'member_no' => '02-001', 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BRA', 'active' => true, 'aliases' => [],
        ], $res->body['clubs'][0], 'sortiert nach Name');
        assertSame(['BC Potsdam', 'SV Berliner Brauereien', 'TSV-Spandau'], array_column($res->body['clubs'], 'name'));
        assertSame(['TSV Spandau 1860'], $res->body['clubs'][2]['aliases']);
        assertSame(false, $res->body['clubs'][2]['active']);
        [$router] = $env->as('ohne-rolle');
        assertSame(403, $get($router, '/api/clubs')->status);
    },
    'Vereine speichern: ändern und anlegen, fehlende bleiben unverändert' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 3, 'name' => 'TSV-Spandau', 'short' => 'TSVS', 'state' => 'BER', 'aliases' => ['TSV Spandau 1860', ' Spandau ']],
            ['name' => 'Neuer Verein e.V.', 'short' => '', 'state' => null, 'aliases' => []],
        ]]);
        assertSame(200, $res->status);
        assertSame([1, 1], [$res->body['created'], $res->body['updated']]);
        $clubs = array_column($get($router, '/api/clubs')->body['clubs'], null, 'name');
        assertSame(4, count($clubs), 'nichts gelöscht');
        assertSame('TSVS', $clubs['TSV-Spandau']['short']);
        assertSame(['Spandau', 'TSV Spandau 1860'], $clubs['TSV-Spandau']['aliases']);
        assertSame('', $clubs['Neuer Verein e.V.']['state']);
        assertSame('BRA', $clubs['BC Potsdam']['state'], 'nicht in der Liste: unverändert');
        assertSame(['SV Berl. Brauereien'], $clubs['SV Berliner Brauereien']['aliases']);
    },
    'Vereine speichern: Spieler werden neu zugeordnet' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['name' => 'Neuer Verein e.V.', 'short' => 'NVE', 'state' => 'BRA'],
            ['id' => 2, 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BRA', 'active' => false],
        ]]);
        $byId = array_column($get($router, '/api/players')->body['players'], null, 'id');
        assertSame('NVE', $byId[3]['club_short']);
        assertSame('BRA', $byId[3]['state']);
        assertSame('', $byId[2]['club_short'], 'inaktiver Verein wird nicht mehr zugeordnet');
    },
    'Vereine speichern: Protokoll mit Feldnamen' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('admin');
        $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [['id' => 1, 'name' => 'SV Berliner Brauereien', 'short' => 'SVBB', 'state' => 'BRA']]]);
        $log = $env->pdo->query("SELECT action, object_id, summary FROM audit_log WHERE object_type = 'club'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([['action' => 'update', 'object_id' => 1, 'summary' => 'fields: state']],
            array_map(static fn(array $l): array => ['action' => $l['action'], 'object_id' => (int) $l['object_id'], 'summary' => $l['summary']], $log));
    },
    'Vereine speichern: coach und ohne Rolle 403, ohne Token 403' => function () use ($setup, $send): void {
        $env = $setup();
        $body = ['clubs' => [['id' => 1, 'name' => 'X', 'short' => '', 'state' => 'BER']]];
        foreach (['trainer', 'ohne-rolle', 'pruefer'] as $person) {
            [$router, $token] = $env->as($person);
            assertSame(403, $send($router, $token, 'PUT', '/api/clubs', $body)->status, $person);
        }
        [$router] = $env->as('kaderpflege');
        assertSame(403, $router->dispatch(Request::fromParts('PUT', '/api/clubs', [], ['Content-Type' => 'application/json'], json_encode($body)))->status);
        assertSame('SV Berliner Brauereien', $env->pdo->query('SELECT name FROM clubs WHERE id = 1')->fetchColumn());
    },
    'Vereine speichern: Mitgliedsnummer und unbekannte Felder 403, nichts gespeichert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 2, 'name' => 'BC Potsdam', 'short' => 'BCP', 'state' => 'BER'],
            ['id' => 1, 'name' => 'SV Berliner Brauereien', 'member_no' => '99', 'imported_at' => '2026-01-01'],
        ]]);
        assertSame(403, $res->status);
        assertSame(['error' => 'forbidden', 'fields' => ['member_no', 'imported_at']], $res->body);
        assertSame('BRA', $env->pdo->query('SELECT state FROM clubs WHERE id = 2')->fetchColumn());
    },
    'Vereine speichern: ungültige Werte 422 mit Pfad, nichts gespeichert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 2, 'name' => 'BC Potsdam', 'state' => 'BER'],
            ['id' => 99, 'name' => 'Unbekannt'],
            ['name' => '  ', 'state' => 'ber', 'short' => str_repeat('x', 21), 'active' => 1, 'aliases' => 'TSV'],
        ]]);
        assertSame(422, $res->status);
        assertSame(['clubs.1.id', 'clubs.2.name', 'clubs.2.short', 'clubs.2.state', 'clubs.2.active', 'clubs.2.aliases'], $res->body['fields']);
        assertSame('BRA', $env->pdo->query('SELECT state FROM clubs WHERE id = 2')->fetchColumn());
        foreach ([[], ['clubs' => []], ['clubs' => 'x'], ['clubs' => [['name' => 'A']], 'mehr' => 1]] as $i => $body) {
            assertSame(422, $send($router, $token, 'PUT', '/api/clubs', $body)->status, 'Body ' . $i);
        }
    },
    'Vereine speichern: Alias eines anderen Vereins ergibt 422, nichts gespeichert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 2, 'name' => 'BC Potsdam', 'state' => 'BER', 'aliases' => ['sv berl. brauereien']],
        ]]);
        assertSame(422, $res->status);
        assertSame('duplicate_alias', $res->body['error']);
        assertSame('BRA', $env->pdo->query('SELECT state FROM clubs WHERE id = 2')->fetchColumn());
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 2, 'name' => 'BC Potsdam', 'aliases' => ['Potsdam', 'POTSDAM']],
        ]]);
        assertSame(422, $res->status, 'doppelt in derselben Liste');
    },
    'Vereine speichern: Name eines vorhandenen Vereins ergibt 422 duplicate_name, nichts gespeichert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        foreach ([
            [['name' => ' bc  POTSDAM ', 'state' => 'BRA']],
            [['name' => 'Neuer Verein'], ['name' => 'neuer verein']],
            [['id' => 3, 'name' => 'SV Berliner Brauereien']],
        ] as $i => $clubs) {
            $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => $clubs]);
            assertSame(422, $res->status, 'Fall ' . $i);
            assertSame('duplicate_name', $res->body['error'], 'Fall ' . $i);
        }
        assertSame(3, $env->count('SELECT COUNT(*) FROM clubs'));
        assertSame('TSV-Spandau', $env->pdo->query('SELECT name FROM clubs WHERE id = 3')->fetchColumn());
        $res = $send($router, $token, 'PUT', '/api/clubs', ['clubs' => [
            ['id' => 1, 'name' => 'BC Potsdam Nord'], ['id' => 2, 'name' => 'SV Berliner Brauereien'],
        ]]);
        assertSame(200, $res->status, 'geprüft wird der Stand nach dem Speichern, nicht ein Zwischenstand');
    },
    'Import mit zu vielen Zeilen: 413' => function () use ($setup, $send): void {
        [$router, $token] = $setup()->as('kaderpflege');
        $rows = array_fill(0, 3001, ['last_name' => 'A', 'first_name' => 'B']);
        assertSame(413, $send($router, $token, 'POST', '/api/players/import', ['rows' => $rows, 'dry_run' => true])->status);
    },
    'Vereine: kein Löschen' => function () use ($setup, $send): void {
        [$router, $token] = $setup()->as('admin');
        assertSame(405, $send($router, $token, 'DELETE', '/api/clubs', ['clubs' => []])->status);
    },
];

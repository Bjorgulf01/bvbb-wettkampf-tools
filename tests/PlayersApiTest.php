<?php
declare(strict_types=1);

use App\Api\AuthController;
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
const PLAYERS_NOW = 1794787200;

/**
 * Testumgebung: migrierte Datenbank mit erfundenen Vereinen und Spielern.
 * Liefert ein Objekt mit pdo und as(person) für eine angemeldete Person.
 */
$setup = static function (): object {
    $pdo = testPdo();
    (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
    $pdo->exec("INSERT INTO clubs (id, member_no, name, short, state) VALUES
        (1, '01-001', 'SV Berliner Brauereien', 'SVBB', 'BER'),
        (2, '02-001', 'BC Potsdam', 'BCP', 'BRA')");
    $pdo->exec("INSERT INTO club_aliases (club_id, alias) VALUES (1, 'SV Berl. Brauereien')");
    $pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, list_no, list_ak, gender, birth_year, roster_status, active, training_site) VALUES
        (1, 'Müßig', 'Lotta', 1, 'SV Berliner Brauereien', 1, 'U19', 'w', 2008, 'LK', 1, 'LLZ'),
        (2, 'Köppen', 'Arvid', 2, 'BC Potsdam', 2, 'U13', 'm', 2015, 'SK', 1, 'BSP'),
        (3, 'Weißkopf', 'Ivo', NULL, 'Neuer Verein e.V.', 3, 'U 09', 'm', NULL, 'LK', 0, 'SLZB')");
    $pdo->exec("INSERT INTO player_private (player_id, email1, sportmed_note, antidoping_note) VALUES
        (1, 'lotta@example.org', 'Erledigt', '15.12.2026'),
        (2, 'arvid@example.org', '26.02.2025', '/')");

    return new class ($pdo) {
        public function __construct(public PDO $pdo)
        {
        }

        /** Router mit eigener Sitzung, angemeldet als $person. Liefert [Router, CSRF-Token]. */
        public function as(string $person): array
        {
            $store = [];
            $clock = static fn(): int => PLAYERS_NOW;
            $cfg = new Config(['env' => 'development', 'test_auth' => true]);
            $session = new Session($store, static function (): void {
            });
            $users = new UserRepo($this->pdo);
            $guard = new AuthGuard($session, $users, 3600, $clock);
            $router = new Router($guard, static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            });
            (new AuthController($session, $users, $guard, new TestAuthProvider(TestAuthProvider::defaultPersonas()), $cfg, $clock))->routes($router);
            (new PlayersController($this->pdo, Permissions::fromDb($this->pdo), $guard, $clock))->routes($router);
            $res = $router->dispatch(Request::fromParts('POST', '/api/auth/test-login', [], ['Content-Type' => 'application/json'], json_encode(['person' => $person])));
            if ($res->status !== 200) {
                throw new RuntimeException('Anmeldung fehlgeschlagen: ' . $person);
            }
            return [$router, $res->body['csrf']];
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

$basicKeys = ['id', 'last_name', 'first_name', 'club_name', 'club_short'];
$rosterKeys = ['list_no', 'list_ak', 'gender', 'birth_year', 'roster_status', 'active', 'training_site', 'state'];
$lightKeys = ['sportmed_status', 'antidoping_status'];
$privateKeys = ['birth_date', 'trainer_info', 'school', 'school_contact', 'sportmed_note', 'antidoping_note',
    'email1', 'email2', 'phone_player', 'phone_mother', 'phone_father', 'shirt_size'];

$sorted = static function (array $keys): array {
    sort($keys, SORT_STRING);
    return $keys;
};

return [
    'coach bekommt einfache und erweiterte Daten, AK und Ampel, nichts Privates' => function () use ($setup, $get, $basicKeys, $rosterKeys, $lightKeys, $sorted): void {
        [$router] = $setup()->as('trainer');
        $res = $get($router, '/api/players', ['year' => '2026']);
        assertSame(200, $res->status);
        assertSame(3, count($res->body['players']));
        foreach ($res->body['players'] as $row) {
            assertSame($sorted(array_merge($basicKeys, $rosterKeys, $lightKeys, ['ak'])), $sorted(array_keys($row)));
        }
    },
    'basic bekommt nur id und einfache Stammdaten' => function () use ($setup, $get, $basicKeys, $sorted): void {
        [$router] = $setup()->as('basis');
        foreach ($get($router, '/api/players')->body['players'] as $row) {
            assertSame($sorted($basicKeys), $sorted(array_keys($row)));
        }
    },
    'roster_editor bekommt die privaten Felder mit' => function () use ($setup, $get, $privateKeys): void {
        [$router] = $setup()->as('kaderpflege');
        $row = $get($router, '/api/players')->body['players'][0];
        foreach ($privateKeys as $k) {
            assertTrue(array_key_exists($k, $row), $k);
        }
    },
    'Person ohne Rolle: 200 und leere Liste' => function () use ($setup, $get): void {
        [$router] = $setup()->as('ohne-rolle');
        $res = $get($router, '/api/players');
        assertSame(200, $res->status);
        assertSame(['players' => []], $res->body);
    },
    'Werte: Umlaute, Typen, Kürzel und Bundesland vom Verein, AK nach Jahr' => function () use ($setup, $get): void {
        [$router] = $setup()->as('trainer');
        $rows = $get($router, '/api/players', ['year' => '2026'])->body['players'];
        $byId = array_column($rows, null, 'id');
        assertSame(['Köppen', 'Müßig', 'Weißkopf'], array_column($rows, 'last_name'), 'sortiert nach Name');
        assertSame('SVBB', $byId[1]['club_short']);
        assertSame('BER', $byId[1]['state']);
        assertSame('BRA', $byId[2]['state']);
        assertSame('', $byId[3]['club_short'], 'unbekannter Verein ohne Kürzel');
        assertSame('', $byId[3]['state']);
        assertSame(2008, $byId[1]['birth_year']);
        assertSame(true, $byId[1]['active']);
        assertSame(false, $byId[3]['active'], 'passive Spieler bleiben in der Liste');
        assertSame('U19', $byId[1]['ak']);
        assertSame('U13', $byId[2]['ak']);
        assertSame('U09', $byId[3]['ak'], 'ohne Jahrgang die AK aus der Liste');
        $next = array_column($get($router, '/api/players', ['year' => '2027'])->body['players'], null, 'id');
        assertSame('O19', $next[1]['ak']);
    },
    'Ampel: coach sieht den Status, nie den Rohtext; basic sieht beides nicht' => function () use ($setup, $get): void {
        $env = $setup();
        [$router] = $env->as('trainer');
        $byId = array_column($get($router, '/api/players', ['year' => '2026'])->body['players'], null, 'id');
        assertSame('done', $byId[1]['sportmed_status']);
        assertSame('scheduled', $byId[1]['antidoping_status']);
        assertSame('open', $byId[2]['sportmed_status']);
        assertSame('open', $byId[2]['antidoping_status']);
        assertSame('open', $byId[3]['sportmed_status'], 'ohne Eintrag offen');
        assertTrue(!array_key_exists('sportmed_note', $byId[1]));
        [$basic] = $env->as('basis');
        $row = $get($basic, '/api/players')->body['players'][0];
        assertTrue(!array_key_exists('sportmed_status', $row) && !array_key_exists('sportmed_note', $row));
    },
    'ungültiges Jahr ergibt 422' => function () use ($setup, $get): void {
        [$router] = $setup()->as('trainer');
        assertSame(422, $get($router, '/api/players', ['year' => '26'])->status);
        assertSame(422, $get($router, '/api/players', ['year' => ['2026']])->status);
        assertSame(200, $get($router, '/api/players')->status, 'ohne Jahr gilt das laufende');
    },
    'einzelner Spieler: Felder nach Rechten, 404 bei unbekannter id' => function () use ($setup, $get): void {
        [$router] = $setup()->as('trainer');
        $res = $get($router, '/api/players/1');
        assertSame(200, $res->status);
        assertSame('Müßig', $res->body['player']['last_name']);
        assertTrue(!array_key_exists('email1', $res->body['player']));
        assertSame(404, $get($router, '/api/players/999')->status);
        assertSame(404, $get($router, '/api/players/abc')->status);
        [$none] = $setup()->as('ohne-rolle');
        assertSame(403, $get($none, '/api/players/1')->status);
    },
    'coach schreibt email1: 403, Wert unverändert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('trainer');
        $res = $send($router, $token, 'PUT', '/api/players/1', ['email1' => 'x@example.org']);
        assertSame(403, $res->status);
        assertSame('forbidden', $res->body['error']);
        assertSame('lotta@example.org', $env->pdo->query('SELECT email1 FROM player_private WHERE player_id = 1')->fetchColumn());
    },
    'coach schreibt ein lesbares Feld: ebenfalls 403' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('trainer');
        assertSame(403, $send($router, $token, 'PUT', '/api/players/1', ['club_name' => 'X'])->status);
        assertSame('SV Berliner Brauereien', $env->pdo->query('SELECT club_name FROM players WHERE id = 1')->fetchColumn());
    },
    'ein verbotenes Feld macht die ganze Anfrage ungültig' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        foreach (['sportmed_status', 'state', 'club_short', 'club_id', 'id', 'created_at', 'ak'] as $field) {
            $res = $send($router, $token, 'PUT', '/api/players/1', ['last_name' => 'Geändert', $field => 'x']);
            assertSame(403, $res->status, $field);
            assertSame([$field], $res->body['fields'], $field);
        }
        assertSame('Müßig', $env->pdo->query('SELECT last_name FROM players WHERE id = 1')->fetchColumn(), 'nichts gespeichert');
    },
    'roster_editor setzt active auf false, Spieler bleibt in der Liste; kein DELETE' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/players/1', ['active' => false]);
        assertSame(200, $res->status);
        assertSame(['active'], $res->body['changed']);
        $ids = array_column($get($router, '/api/players')->body['players'], 'id');
        assertTrue(in_array(1, $ids, true));
        assertSame(405, $send($router, $token, 'DELETE', '/api/players/1', [])->status);
        assertSame(3, (int) $env->pdo->query('SELECT COUNT(*) FROM players')->fetchColumn());
    },
    'Protokoll nennt geänderte Felder, nie Werte' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/players/1', ['email1' => 'neu@example.org', 'last_name' => 'Müßig']);
        assertSame(['email1'], $res->body['changed'], 'unveränderter Name zählt nicht');
        $row = $env->pdo->query("SELECT user_id, action, object_type, object_id, summary FROM audit_log WHERE object_type = 'player'")->fetch(PDO::FETCH_ASSOC);
        assertSame('update', $row['action']);
        assertSame(1, (int) $row['object_id']);
        assertSame('fields: email1', $row['summary']);
        assertTrue($row['user_id'] !== null, 'wer geändert hat');
        $all = implode(' ', $env->pdo->query('SELECT summary FROM audit_log')->fetchAll(PDO::FETCH_COLUMN));
        assertTrue(!str_contains($all, 'neu@example.org') && !str_contains($all, 'lotta@'), 'kein Wert im Protokoll');
        $send($router, $token, 'PUT', '/api/players/1', ['email1' => 'neu@example.org']);
        assertSame(1, (int) $env->pdo->query("SELECT COUNT(*) FROM audit_log WHERE object_type = 'player'")->fetchColumn(), 'ohne Änderung kein Eintrag');
    },
    'Vereinszuordnung wird beim Speichern aus dem Vereinstext abgeleitet' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/players/1', ['club_name' => ' bc potsdam ']);
        assertSame(['club_name', 'club_id'], $res->body['changed']);
        $row = $get($router, '/api/players/1')->body['player'];
        assertSame('BRA', $row['state']);
        assertSame('BCP', $row['club_short']);
        assertSame('bc potsdam', $row['club_name'], 'Originaltext bleibt, nur am Rand gekürzt');
        $send($router, $token, 'PUT', '/api/players/1', ['club_name' => 'SV Berl. Brauereien']);
        assertSame(1, (int) $env->pdo->query('SELECT club_id FROM players WHERE id = 1')->fetchColumn(), 'über Alias');
        $send($router, $token, 'PUT', '/api/players/1', ['club_name' => 'Gibt es nicht']);
        assertSame(null, $env->pdo->query('SELECT club_id FROM players WHERE id = 1')->fetchColumn(), 'unbekannt: leer');
    },
    'Anlegen: 201, Pflichtfelder, Protokoll, private Daten' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, 'POST', '/api/players', [
            'last_name' => 'Möllendorf', 'first_name' => 'Jürgen', 'club_name' => 'SV Berliner Brauereien',
            'birth_year' => 2014, 'email1' => 'juergen@example.org', 'birth_date' => '2014-09-14',
        ]);
        assertSame(201, $res->status);
        $id = $res->body['id'];
        $row = $get($router, '/api/players/' . $id)->body['player'];
        assertSame('Möllendorf', $row['last_name']);
        assertSame('SVBB', $row['club_short']);
        assertSame('juergen@example.org', $row['email1']);
        assertSame('2014-09-14', $row['birth_date']);
        assertSame(true, $row['active'], 'neu angelegt ist aktiv');
        $audit = $env->pdo->query("SELECT action, summary FROM audit_log WHERE object_type = 'player'")->fetch(PDO::FETCH_ASSOC);
        assertSame('create', $audit['action']);
        assertTrue(!str_contains($audit['summary'], 'Möllendorf') && !str_contains($audit['summary'], 'juergen@'));

        assertSame(422, $send($router, $token, 'POST', '/api/players', ['last_name' => 'Ohne Vorname'])->status);
        assertSame(422, $send($router, $token, 'POST', '/api/players', ['last_name' => '  ', 'first_name' => 'X'])->status);
        [$coach, $ct] = $env->as('trainer');
        assertSame(403, $send($coach, $ct, 'POST', '/api/players', ['last_name' => 'A', 'first_name' => 'B'])->status);
        assertSame(4, (int) $env->pdo->query('SELECT COUNT(*) FROM players')->fetchColumn());
    },
    'ungültige Werte ergeben 422 und speichern nichts' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        foreach ([
            ['birth_year' => 'abc'], ['birth_year' => 1800], ['list_no' => -1], ['active' => 'ja'],
            ['birth_date' => '31.02.2014'], ['birth_date' => '2014-02-31'], ['last_name' => str_repeat('a', 101)],
            ['last_name' => ''], ['email1' => ['x']], ['gender' => 5],
        ] as $body) {
            $res = $send($router, $token, 'PUT', '/api/players/1', $body + ['first_name' => 'Geändert']);
            assertSame(422, $res->status, json_encode($body));
            assertSame([array_key_first($body)], $res->body['fields'], json_encode($body));
        }
        assertSame('Lotta', $env->pdo->query('SELECT first_name FROM players WHERE id = 1')->fetchColumn());
        $res = $send($router, $token, 'PUT', '/api/players/1', ['birth_year' => null, 'email1' => '']);
        assertSame(200, $res->status, 'leeren ist erlaubt');
        assertSame(null, $env->pdo->query('SELECT email1 FROM player_private WHERE player_id = 1')->fetchColumn());
    },
    'Body muss ein Objekt sein, unbekannter Spieler 404' => function () use ($setup, $send): void {
        [$router, $token] = $setup()->as('kaderpflege');
        $res = $send($router, $token, 'PUT', '/api/players/1', [1, 2]);
        assertSame(403, $res->status, 'Liste: Schlüssel 0 und 1 sind unbekannte Felder');
        assertSame(['0', '1'], $res->body['fields']);
        assertSame(403, $router->dispatch(Request::fromParts('PUT', '/api/players/1', [], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token], '{"0":"x"}'))->status);
        assertSame(422, $send($router, $token, 'PUT', '/api/players/1', [])->status);
        assertSame(404, $send($router, $token, 'PUT', '/api/players/999', ['first_name' => 'X'])->status);
    },
    'Schreiben ohne CSRF-Token: 403' => function () use ($setup): void {
        [$router] = $setup()->as('kaderpflege');
        $res = $router->dispatch(Request::fromParts('PUT', '/api/players/1', [], ['Content-Type' => 'application/json'], '{"first_name":"X"}'));
        assertSame(403, $res->status);
        assertSame(['error' => 'csrf'], $res->body);
    },
    'mehrere Rollen: Vereinigung der Rechte beim Lesen und Schreiben' => function () use ($setup, $send, $get, $basicKeys, $rosterKeys, $lightKeys, $privateKeys, $sorted): void {
        $env = $setup();
        [$router, $token] = $env->as('trainer-kaderpflege');
        $row = $get($router, '/api/players/1')->body['player'];
        assertSame($sorted(array_merge($basicKeys, $rosterKeys, $lightKeys, $privateKeys, ['ak'])), $sorted(array_keys($row)));
        assertSame(200, $send($router, $token, 'PUT', '/api/players/1', ['email1' => 'neu@example.org'])->status);
        assertSame('neu@example.org', $env->pdo->query('SELECT email1 FROM player_private WHERE player_id = 1')->fetchColumn());
    },
    'Anlegen ohne Schreibrecht auf die Stammdaten: 403 statt 422' => function () use ($setup, $send): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO role_permissions (role_id, perm_group, mode) SELECT id, 'player.private', 'write' FROM roles WHERE role_key = 'health_staff'");
        [$router, $token] = $env->as('nada');
        $res = $send($router, $token, 'POST', '/api/players', ['email1' => 'x@example.org']);
        assertSame(403, $res->status);
        assertSame(['last_name', 'first_name'], $res->body['fields']);
    },
    'Leerraum aus Excel am Rand und Steuerzeichen' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        assertSame(200, $send($router, $token, 'PUT', '/api/players/1', ['last_name' => "\u{FEFF}Müßig\u{00A0}", 'first_name' => "Lotta\u{200B}"])->status);
        assertSame('Müßig', $env->pdo->query('SELECT last_name FROM players WHERE id = 1')->fetchColumn());
        assertSame('Lotta', $env->pdo->query('SELECT first_name FROM players WHERE id = 1')->fetchColumn());
        foreach (["Lotta\u{0000}", "Lot\nta", "\u{001B}x"] as $bad) {
            $res = $send($router, $token, 'PUT', '/api/players/1', ['first_name' => $bad]);
            assertSame(422, $res->status, json_encode($bad));
        }
        assertSame(200, $send($router, $token, 'PUT', '/api/players/1', ['trainer_info' => "Zeile 1\nZeile 2\tTab"])->status, 'Zeilenumbruch im Freitext erlaubt');
        assertSame(422, $send($router, $token, 'PUT', '/api/players/1', ['sportmed_note' => str_repeat('x', 201)])->status, 'Textspalte höchstens 200 Zeichen');
    },
    'inaktiver Verein wird beim Speichern nicht zugeordnet' => function () use ($setup, $send): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO clubs (id, member_no, name, short, state, active) VALUES (3, '01-099', 'BC Alt', 'BCA', 'BER', 0)");
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, 'PUT', '/api/players/1', ['club_name' => 'BC Alt']);
        assertSame(null, $env->pdo->query('SELECT club_id FROM players WHERE id = 1')->fetchColumn());
    },
    'private Daten ohne Zeile in player_private' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $row = array_column($get($router, '/api/players')->body['players'], null, 'id')[3];
        assertSame(null, $row['email1']);
        assertSame(200, $send($router, $token, 'PUT', '/api/players/3', ['school' => 'Grundschule am See'])->status);
        assertSame('Grundschule am See', $env->pdo->query('SELECT school FROM player_private WHERE player_id = 3')->fetchColumn());
    },
];

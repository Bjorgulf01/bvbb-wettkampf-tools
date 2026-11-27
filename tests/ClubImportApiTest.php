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
const CLUB_IMPORT_NOW = 1794787200;

/**
 * Testumgebung: migrierte Datenbank mit erfundenen Vereinen und Spielern.
 * Verein 1 hat eine Mitgliedsnummer, Verein 2 wurde von Hand angelegt (ohne Nummer).
 */
$setup = static function (): object {
    $pdo = testPdo();
    (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
    $pdo->exec("INSERT INTO clubs (id, member_no, name, short, state) VALUES
        (1, '08-0040', 'SV Sankt Altdorf', 'SVA', 'BRA'),
        (2, NULL, 'BC Handgepflegt', 'BCH', 'BER')");
    $pdo->exec("INSERT INTO club_aliases (club_id, alias) VALUES (1, 'Sankt A')");
    $pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, gender, birth_year, roster_status, active) VALUES
        (1, 'Müßig', 'Lotta', NULL, 'Köpi Test', 'w', 2012, 'LK', 1),
        (2, 'Köppen', 'Arvid', 2, 'BC Handgepflegt', 'm', 2013, 'SK', 1)");

    return new class ($pdo) {
        public function __construct(public PDO $pdo)
        {
        }

        public function as(string $person): array
        {
            $store = [];
            $clock = static fn(): int => CLUB_IMPORT_NOW;
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

        public function snapshot(): string
        {
            return json_encode([
                $this->pdo->query('SELECT * FROM clubs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT * FROM club_aliases ORDER BY club_id, alias')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT id, club_id FROM players ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
            ]);
        }

        /** @return array<string, mixed> Verein nach Name aus GET /api/clubs */
        public function club(Router $router, string $name): ?array
        {
            $res = $router->dispatch(new Request('GET', '/api/clubs', []));
            foreach ($res->body['clubs'] as $c) {
                if ($c['name'] === $name) {
                    return $c;
                }
            }
            return null;
        }
    };
};

$send = static function (Router $router, string $token, $body): Response {
    return $router->dispatch(Request::fromParts('POST', '/api/clubs/import', [], [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
    ], json_encode($body)));
};

/** Eine Zeile wie aus dem Browser (sieben Spalten des nuLiga-Exports); $over ändert einzelne Felder. */
$row = static fn(array $over = []): array => array_merge([
    'verein_nr' => '41',
    'dbv_nr' => '08-0041',
    'name' => 'TSV Köpenicker Testclub',
    'short_name' => null,
    'print_name' => null,
    'status' => 'aktiv',
    'is_federation' => false,
], $over);

/** Drei Zeilen: neu aktiv, neu Archiv, Verband. */
$three = static fn(): array => [
    $row(),
    $row(['verein_nr' => '507', 'dbv_nr' => null, 'name' => 'SG Grünau Test', 'status' => 'Archiv']),
    $row(['verein_nr' => '9999', 'dbv_nr' => null, 'name' => 'SV Spielberechtigung Test', 'is_federation' => true]),
];

return [
    'Vorschau zählt und ändert nichts' => function () use ($setup, $send, $three): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $res = $send($router, $token, ['rows' => $three(), 'dry_run' => true]);
        assertSame(200, $res->status);
        assertSame(2, $res->body['create']);
        assertSame(0, $res->body['update']);
        assertSame(0, $res->body['unchanged']);
        assertSame([['row' => 3, 'reason' => 'federation']], $res->body['skipped']);
        assertSame([['row' => 2, 'code' => 'member_no_derived', 'detail' => '08-0507']], $res->body['warnings']);
        assertSame($before, $env->snapshot());
    },
    'Übernehmen legt Vereine an, Archiv inaktiv, ohne Kürzel und Bundesland' => function () use ($setup, $send, $three): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, ['rows' => $three(), 'dry_run' => false]);
        assertSame(200, $res->status);
        assertSame(2, $res->body['create']);
        $a = $env->club($router, 'TSV Köpenicker Testclub');
        assertSame('08-0041', $a['member_no']);
        assertSame(true, $a['active']);
        assertSame('', $a['short']);
        assertSame('', $a['state']);
        $b = $env->club($router, 'SG Grünau Test');
        assertSame('08-0507', $b['member_no']);
        assertSame(false, $b['active']);
        assertSame(null, $env->club($router, 'SV Spielberechtigung Test'));
        assertSame(2, $env->count('SELECT COUNT(*) FROM clubs WHERE imported_at IS NOT NULL'));
        assertSame(2, $env->count("SELECT COUNT(*) FROM audit_log WHERE action = 'club_import_create'"));
    },
    'Review Focus 3: zweiter Lauf ändert nichts' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $rows = [$row(['short_name' => 'Köpenicker TC', 'print_name' => 'KTC']), $row(['verein_nr' => '507', 'dbv_nr' => null, 'name' => 'SG Grünau Test', 'status' => 'Archiv'])];
        $send($router, $token, ['rows' => $rows, 'dry_run' => false]);
        $aliases = $env->count('SELECT COUNT(*) FROM club_aliases');
        $audit = $env->count('SELECT COUNT(*) FROM audit_log');
        $before = $env->snapshot();
        $res = $send($router, $token, ['rows' => $rows, 'dry_run' => false]);
        assertSame(0, $res->body['create']);
        assertSame(0, $res->body['update']);
        assertSame(2, $res->body['unchanged']);
        assertSame($aliases, $env->count('SELECT COUNT(*) FROM club_aliases'));
        assertSame($audit, $env->count('SELECT COUNT(*) FROM audit_log'));
        assertSame($before, $env->snapshot(), 'auch imported_at bleibt');
    },
    'Review Focus 4: Kürzel, Bundesland und Alias von Hand bleiben' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, ['rows' => [$row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Altdorf', 'short_name' => 'SV St. Altdorf', 'print_name' => 'St. Altd.'])], 'dry_run' => false]);
        assertSame(1, $res->body['update']);
        $c = $env->club($router, 'SV Sankt Altdorf');
        assertSame('SVA', $c['short']);
        assertSame('BRA', $c['state']);
        assertSame(['Sankt A', 'St. Altd.', 'SV St. Altdorf'], $c['aliases']);
    },
    'Verein von Hand wird über den Namen verknüpft und behält seine Daten' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('admin');
        $res = $send($router, $token, ['rows' => [$row(['verein_nr' => '55', 'dbv_nr' => '08-0055', 'name' => 'BC Handgepflegt'])], 'dry_run' => false]);
        assertSame(1, $res->body['update']);
        assertSame('linked_by_name', $res->body['warnings'][0]['code']);
        $c = $env->club($router, 'BC Handgepflegt');
        assertSame(2, $c['id']);
        assertSame('08-0055', $c['member_no']);
        assertSame('BCH', $c['short']);
        assertSame('BER', $c['state']);
    },
    'Neuableitung: Spieler bekommt den Verein über den neuen Alias, inaktiver Verein verliert die Zuordnung' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, ['rows' => [
            $row(['print_name' => 'Köpi Test']),
            $row(['verein_nr' => '55', 'dbv_nr' => null, 'name' => 'BC Handgepflegt', 'status' => 'Archiv']),
        ], 'dry_run' => false]);
        assertSame(200, $res->status);
        $id = $env->club($router, 'TSV Köpenicker Testclub')['id'];
        assertSame((string) $id, (string) $env->pdo->query('SELECT club_id FROM players WHERE id = 1')->fetchColumn());
        assertSame(null, $env->pdo->query('SELECT club_id FROM players WHERE id = 2')->fetchColumn());
        $issues = $router->dispatch(new Request('GET', '/api/players/issues', ['year' => '2026']))->body['issues'];
        assertSame(['unknown_club'], array_column(array_filter($issues, static fn(array $i): bool => $i['id'] === 2), 'code'));
    },
    'Zusätzlicher Schlüssel wie iban ergibt 403, nichts gespeichert' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $rows = [$row(), $row(['verein_nr' => '42', 'iban' => 'DE00123456780000000000'])];
        foreach ([true, false] as $dryRun) {
            $res = $send($router, $token, ['rows' => $rows, 'dry_run' => $dryRun]);
            assertSame(403, $res->status);
            assertSame(['error' => 'forbidden', 'fields' => ['iban']], $res->body);
        }
        assertSame($before, $env->snapshot());
    },
    'Rechte: Trainer und Basis 403' => function () use ($setup, $send, $three): void {
        $env = $setup();
        foreach (['trainer', 'basis', 'ohne-rolle'] as $p) {
            [$router, $token] = $env->as($p);
            assertSame(403, $send($router, $token, ['rows' => $three(), 'dry_run' => false])->status, $p);
        }
        assertSame(2, $env->count('SELECT COUNT(*) FROM clubs'));
    },
    'Body: dry_run Pflicht und boolesch, rows Liste' => function () use ($setup, $send, $three): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        foreach ([['rows' => $three()], ['rows' => $three(), 'dry_run' => 'false'], ['rows' => 'x', 'dry_run' => true],
            ['rows' => ['a' => $three()[0]], 'dry_run' => true], ['rows' => [], 'dry_run' => true],
            ['rows' => $three(), 'dry_run' => true, 'extra' => 1], ['rows' => ['kein Objekt'], 'dry_run' => true]] as $body) {
            $res = $send($router, $token, $body);
            assertSame(422, $res->status, json_encode($body));
            assertSame('invalid_body', $res->body['error']);
        }
    },
    'Mehr als 3000 Zeilen ergeben 413' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        assertSame(413, $send($router, $token, ['rows' => array_fill(0, 3001, $row()), 'dry_run' => true])->status);
    },
    'Falsche Typen und Steuerzeichen ergeben 422 mit Pfad' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $res = $send($router, $token, ['rows' => [
            $row(),
            $row(['name' => 5, 'is_federation' => 'nein']),
            $row(['short_name' => "A\u{0007}B", 'dbv_nr' => 41]),
            $row(['verein_nr' => null, 'print_name' => str_repeat('x', 191)]),
        ], 'dry_run' => false]);
        assertSame(422, $res->status);
        assertSame(['error' => 'invalid', 'fields' => ['rows.1.name', 'rows.1.is_federation', 'rows.2.dbv_nr', 'rows.2.short_name', 'rows.3.verein_nr', 'rows.3.print_name']], $res->body);
        assertSame($before, $env->snapshot());
    },
    'Fehlender Schlüssel ergibt 422' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $r = $row();
        unset($r['print_name']);
        $res = $send($router, $token, ['rows' => [$r], 'dry_run' => true]);
        assertSame(422, $res->status);
        assertSame(['rows.0.print_name'], $res->body['fields']);
    },
    'Texte werden getrimmt, auch geschütztes Leerzeichen und BOM' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, ['rows' => [$row(['name' => "\u{FEFF}TSV Köpenicker Testclub\u{00A0}", 'verein_nr' => ' 41 '])], 'dry_run' => false]);
        assertSame('08-0041', $env->club($router, 'TSV Köpenicker Testclub')['member_no']);
    },
    'Protokoll nennt Feldnamen, keine Vereinsnamen' => function () use ($setup, $send, $row): void {
        $env = $setup();
        [$router, $token] = $env->as('kaderpflege');
        $send($router, $token, ['rows' => [$row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Neudorf', 'print_name' => 'Neud.'])], 'dry_run' => false]);
        $log = $env->pdo->query("SELECT action, object_type, object_id, summary FROM audit_log WHERE action LIKE 'club_import%'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([['action' => 'club_import_update', 'object_type' => 'club', 'object_id' => 1, 'summary' => 'fields: name, aliases']], array_map(static function (array $l): array {
            $l['object_id'] = (int) $l['object_id'];
            return $l;
        }, $log));
    },
    'Umbenennung behält den alten Namen als Alias, Spieler bleiben beim Verein' => function () use ($setup, $send, $row): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, gender, roster_status, active) VALUES (3, 'Weißkopf', 'Ivo', 1, 'SV Sankt Altdorf', 'm', 'LK', 1)");
        [$router, $token] = $env->as('kaderpflege');
        $before = $env->snapshot();
        $res = $send($router, $token, ['rows' => [$row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Neudorf'])], 'dry_run' => true]);
        assertSame(['unlinked' => 0, 'moved' => 0], $res->body['players']);
        assertSame(1, $res->body['update']);
        assertSame($before, $env->snapshot(), 'Vorschau ändert nichts');
        $done = $send($router, $token, ['rows' => [$row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Neudorf'])], 'dry_run' => false]);
        assertSame(['unlinked' => 0, 'moved' => 0], $done->body['players']);
        assertSame(1, (int) $env->pdo->query('SELECT club_id FROM players WHERE id = 3')->fetchColumn());
        assertSame(['SV Sankt Altdorf'], $env->pdo->query("SELECT alias FROM club_aliases WHERE club_id = 1 AND alias = 'SV Sankt Altdorf'")->fetchAll(PDO::FETCH_COLUMN));
    },
    'Vorschau nennt Spieler, die ihren Verein verlieren, wenn der alte Name einem anderen Verein gehört' => function () use ($setup, $send, $row): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, gender, roster_status, active) VALUES (3, 'Weißkopf', 'Ivo', 1, 'SV Sankt Altdorf', 'm', 'LK', 1)");
        $env->pdo->exec("INSERT INTO club_aliases (club_id, alias) VALUES (2, 'SV Sankt Altdorf')");
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, ['rows' => [$row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Neudorf'])], 'dry_run' => true]);
        assertSame(1, $res->body['players']['unlinked'] + $res->body['players']['moved']);
        assertSame(['renamed', 'alias_conflict'], array_map(static fn(array $w): string => $w['code'], $res->body['warnings']));
    },
    'Vorschau nennt Spieler, die zu einem neuen Verein wechseln, mit Warnung' => function () use ($setup, $send, $row): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO players (id, last_name, first_name, club_id, club_name, gender, roster_status, active) VALUES (3, 'Weißkopf', 'Ivo', 1, 'Sankt A', 'm', 'LK', 1)");
        [$router, $token] = $env->as('kaderpflege');
        $res = $send($router, $token, ['rows' => [$row(['name' => 'Sankt A'])], 'dry_run' => true]);
        assertSame(['unlinked' => 0, 'moved' => 1], $res->body['players']);
        assertSame([['row' => 1, 'code' => 'name_matches_alias', 'detail' => 'SV Sankt Altdorf']], $res->body['warnings']);
    },
    'Alias, der nur nach der Sortierung der Datenbank doppelt ist, zählt als unverändert' => function () use ($setup, $send, $row): void {
        $env = $setup();
        $env->pdo->exec("INSERT INTO club_aliases (club_id, alias) VALUES (2, 'Handgepflegt A')");
        [$router, $token] = $env->as('kaderpflege');
        $r = $row(['verein_nr' => '40', 'dbv_nr' => '08-0040', 'name' => 'SV Sankt Altdorf', 'short_name' => 'Handgepflegt Ä']);
        foreach ([true, false, false] as $dryRun) {
            $res = $send($router, $token, ['rows' => [$r], 'dry_run' => $dryRun]);
            assertSame(0, $res->body['update'], 'dry_run ' . var_export($dryRun, true));
            assertSame(1, $res->body['unchanged']);
        }
        assertSame(0, $env->count("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'club_import%'"));
    },
    'CSRF: ohne Token 403' => function () use ($setup, $three): void {
        $env = $setup();
        [$router] = $env->as('kaderpflege');
        $res = $router->dispatch(Request::fromParts('POST', '/api/clubs/import', [], ['Content-Type' => 'application/json'], json_encode(['rows' => $three(), 'dry_run' => false])));
        assertSame(403, $res->status);
        assertSame(2, $env->count('SELECT COUNT(*) FROM clubs'));
    },
];

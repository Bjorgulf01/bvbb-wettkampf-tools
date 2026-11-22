<?php
declare(strict_types=1);

use App\Api\AuditController;
use App\Api\AuthController;
use App\Api\UsersController;
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

/**
 * Testumgebung: migrierte Datenbank, Testpersonen aus TestAuthProvider::defaultPersonas().
 * as(person) meldet an und liefert [Router, CSRF-Token, eigene id]. Jede Anmeldung hat ihre eigene Sitzung.
 */
$setup = static function (): object {
    $pdo = testPdo();
    (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();

    return new class ($pdo) {
        public function __construct(public PDO $pdo)
        {
        }

        /** @return array{0: Router, 1: string, 2: int} */
        public function as(string $person): array
        {
            [$router, $res] = $this->login($person);
            if ($res->status !== 200) {
                throw new RuntimeException('Anmeldung fehlgeschlagen: ' . $person);
            }
            return [$router, $res->body['csrf'], $res->body['id']];
        }

        /** @return array{0: Router, 1: Response} */
        public function login(string $person): array
        {
            $store = [];
            $cfg = new Config(['env' => 'development', 'test_auth' => true]);
            $session = new Session($store, static function (): void {
            });
            $users = new UserRepo($this->pdo);
            $guard = new AuthGuard($session, $users, 3600);
            $router = new Router($guard, static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            });
            (new AuthController($session, $users, $guard, new TestAuthProvider(TestAuthProvider::defaultPersonas()), $cfg))->routes($router);
            $permissions = Permissions::fromDb($this->pdo);
            (new UsersController($this->pdo, $permissions, $guard))->routes($router);
            (new AuditController($this->pdo, $permissions, $guard))->routes($router);
            $res = $router->dispatch(Request::fromParts('POST', '/api/auth/test-login', [], ['Content-Type' => 'application/json'], json_encode(['person' => $person])));
            return [$router, $res];
        }

        public function idOf(string $person): int
        {
            return $this->as($person)[2];
        }

        public function count(string $sql): int
        {
            return (int) $this->pdo->query($sql)->fetchColumn();
        }

        public function snapshot(): string
        {
            return json_encode([
                $this->pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT * FROM user_roles ORDER BY user_id, role_id')->fetchAll(PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
            ]);
        }
    };
};

$send = static function (Router $router, ?string $token, string $method, string $path, $body): Response {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== null) {
        $headers['X-CSRF-Token'] = $token;
    }
    return $router->dispatch(Request::fromParts($method, $path, [], $headers, json_encode($body)));
};

$get = static fn(Router $router, string $path, array $query = []): Response =>
    $router->dispatch(new Request('GET', $path, $query));

$userById = static function (Response $res, int $id): ?array {
    foreach ($res->body['users'] as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
};

return [
    'admin setzt Rollen, GET /api/users zeigt sie, Protokolleintrag „roles“' => function () use ($setup, $send, $get, $userById): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token, $adminId] = $env->as('admin');
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['roster_editor', 'coach', 'coach']]);
        assertSame(200, $res->status);
        assertSame(['coach', 'roster_editor'], $res->body['user']['roles'], 'sortiert, doppelte einmal');
        $list = $get($router, '/api/users');
        assertSame(200, $list->status);
        assertSame(['coach', 'roster_editor'], $userById($list, $coachId)['roles']);
        $entry = $env->pdo->query("SELECT user_id, object_type, object_id, summary FROM audit_log WHERE action = 'roles'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([['user_id' => $adminId, 'object_type' => 'user', 'object_id' => $coachId, 'summary' => 'roles: coach, roster_editor']], $entry);
    },
    'GET /api/users: Felder je Person und alle Rollenschlüssel, keine Kennung des Anmeldedienstes' => function () use ($setup, $get, $userById): void {
        $env = $setup();
        [$router, , $adminId] = $env->as('admin');
        $res = $get($router, '/api/users');
        $me = $userById($res, $adminId);
        assertSame(['id', 'name', 'email', 'active', 'roles'], array_keys($me));
        assertSame(true, $me['active']);
        assertSame(['admin'], $me['roles']);
        assertSame(['admin', 'basic', 'coach', 'health_staff', 'reviewer', 'roster_editor'], $res->body['role_keys']);
        assertTrue(!str_contains(json_encode($res->body), 'test:'), 'subject wird nicht ausgeliefert');
    },
    'unbekannter Rollenschlüssel: 422, nichts geändert' => function () use ($setup, $send): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token] = $env->as('admin');
        $before = $env->snapshot();
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['coach', 'chef']]);
        assertSame(422, $res->status);
        assertSame(['error' => 'unknown_role', 'roles' => ['chef']], $res->body);
        assertSame($before, $env->snapshot());
    },
    'Rollenschlüssel exakt: andere Schreibung, Umlaut, zu lang oder zu viele ergeben 422' => function () use ($setup, $send): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token] = $env->as('admin');
        $before = $env->snapshot();
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['Admin', 'ädmin', 'coach ']]);
        assertSame(422, $res->status);
        assertSame(['error' => 'unknown_role', 'roles' => ['Admin', 'coach ', 'ädmin']], $res->body);
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => [str_repeat('x', 41)]]);
        assertSame(['error' => 'invalid', 'fields' => ['roles']], $res->body, 'zu langer Schlüssel wird nicht zurückgegeben');
        assertSame(422, $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => array_fill(0, 21, 'coach')])->status);
        assertSame($before, $env->snapshot());
    },
    'admin deaktiviert sich selbst, wenn es einen zweiten aktiven admin gibt' => function () use ($setup, $send, $get): void {
        $env = $setup();
        $otherId = $env->idOf('kaderpflege');
        [$router, $token, $adminId] = $env->as('admin');
        $send($router, $token, 'PUT', '/api/users/' . $otherId . '/roles', ['roles' => ['admin']]);
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $adminId, ['active' => false])->status);
        assertSame(['error' => 'inactive'], $get($router, '/api/me')->body, 'eigene Sitzung endet');
    },
    'ein inaktiver admin darf wieder aktiviert werden' => function () use ($setup, $send): void {
        $env = $setup();
        $otherId = $env->idOf('kaderpflege');
        [$router, $token] = $env->as('admin');
        $send($router, $token, 'PUT', '/api/users/' . $otherId . '/roles', ['roles' => ['admin']]);
        $send($router, $token, 'PUT', '/api/users/' . $otherId, ['active' => false]);
        $res = $send($router, $token, 'PUT', '/api/users/' . $otherId, ['active' => true]);
        assertSame(200, $res->status);
        assertSame(true, $res->body['user']['active']);
    },
    'Rollen: ungültiger Body ergibt 422, zusätzliche Felder 403' => function () use ($setup, $send): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token] = $env->as('admin');
        $before = $env->snapshot();
        foreach ([[], ['roles' => 'coach'], ['roles' => [1]], ['roles' => ['a' => 'coach']], ['roles' => [null]]] as $body) {
            assertSame(422, $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', $body)->status, json_encode($body));
        }
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['coach'], 'active' => false]);
        assertSame(403, $res->status);
        assertSame(['error' => 'forbidden', 'fields' => ['active']], $res->body);
        assertSame($before, $env->snapshot());
    },
    'dem letzten admin die Rolle admin entziehen: 409, nichts geändert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token, $adminId] = $env->as('admin');
        $before = $env->snapshot();
        $res = $send($router, $token, 'PUT', '/api/users/' . $adminId . '/roles', ['roles' => ['roster_editor']]);
        assertSame(409, $res->status);
        assertSame(['error' => 'last_admin'], $res->body);
        assertSame($before, $env->snapshot());
    },
    'mit einem zweiten aktiven admin darf einer die Rolle abgeben' => function () use ($setup, $send): void {
        $env = $setup();
        $otherId = $env->idOf('kaderpflege');
        [$router, $token, $adminId] = $env->as('admin');
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $otherId . '/roles', ['roles' => ['admin', 'roster_editor']])->status);
        $res = $send($router, $token, 'PUT', '/api/users/' . $adminId . '/roles', ['roles' => []]);
        assertSame(200, $res->status);
        assertSame([], $res->body['user']['roles']);
    },
    'ein inaktiver admin zählt nicht: der letzte aktive admin bleibt geschützt' => function () use ($setup, $send): void {
        $env = $setup();
        $otherId = $env->idOf('kaderpflege');
        [$router, $token, $adminId] = $env->as('admin');
        $send($router, $token, 'PUT', '/api/users/' . $otherId . '/roles', ['roles' => ['admin']]);
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $otherId, ['active' => false])->status);
        assertSame(409, $send($router, $token, 'PUT', '/api/users/' . $adminId . '/roles', ['roles' => ['coach']])->status);
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $otherId . '/roles', ['roles' => []])->status,
            'einem inaktiven admin darf die Rolle entzogen werden');
    },
    'den letzten admin deaktivieren: 409, nichts geändert' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token, $adminId] = $env->as('admin');
        $before = $env->snapshot();
        $res = $send($router, $token, 'PUT', '/api/users/' . $adminId, ['active' => false]);
        assertSame(409, $res->status);
        assertSame(['error' => 'last_admin'], $res->body);
        assertSame($before, $env->snapshot());
    },
    'Person mit active=false bekommt bei der Anmeldung 403 inactive, laufende Sitzung endet' => function () use ($setup, $send, $get): void {
        $env = $setup();
        [$coachRouter, , $coachId] = $env->as('trainer');
        [$router, $token, $adminId] = $env->as('admin');
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => false]);
        assertSame(200, $res->status);
        assertSame(false, $res->body['user']['active']);
        [, $login] = $env->login('trainer');
        assertSame(403, $login->status);
        assertSame(['error' => 'inactive'], $login->body);
        assertSame(403, $get($coachRouter, '/api/me')->status, 'laufende Sitzung');
        $entry = $env->pdo->query("SELECT user_id, action, object_id FROM audit_log WHERE object_type = 'user' AND action = 'deactivate'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([['user_id' => $adminId, 'action' => 'deactivate', 'object_id' => $coachId]], $entry);
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => true])->status);
        assertSame(200, $env->login('trainer')[1]->status, 'wieder aktiv: Anmeldung klappt');
        assertSame(1, $env->count("SELECT COUNT(*) FROM audit_log WHERE action = 'activate'"));
    },
    'ohne Änderung kein Protokolleintrag' => function () use ($setup, $send): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token] = $env->as('admin');
        $before = $env->count('SELECT COUNT(*) FROM audit_log');
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['coach']])->status);
        assertSame(200, $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => true])->status);
        assertSame($before, $env->count('SELECT COUNT(*) FROM audit_log'));
    },
    'PUT /api/users/{id}: nur active (Mass Assignment 403), Wert nur true oder false (422)' => function () use ($setup, $send): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token] = $env->as('admin');
        $before = $env->snapshot();
        $res = $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => true, 'name' => 'Chef', 'subject' => 'test:admin']);
        assertSame(403, $res->status);
        assertSame(['error' => 'forbidden', 'fields' => ['name', 'subject']], $res->body);
        assertSame(403, $send($router, $token, 'PUT', '/api/users/' . $coachId, ['roles' => ['admin']])->status);
        assertSame(422, $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => 'nein'])->status);
        assertSame(422, $send($router, $token, 'PUT', '/api/users/' . $coachId, ['active' => 0])->status);
        assertSame(422, $send($router, $token, 'PUT', '/api/users/' . $coachId, [])->status);
        assertSame($before, $env->snapshot());
    },
    'unbekannte Person: 404' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token] = $env->as('admin');
        assertSame(404, $send($router, $token, 'PUT', '/api/users/999/roles', ['roles' => []])->status);
        assertSame(404, $send($router, $token, 'PUT', '/api/users/999', ['active' => false])->status);
        assertSame(404, $send($router, $token, 'PUT', '/api/users/abc', ['active' => false])->status);
        assertSame(404, $send($router, $token, 'PUT', '/api/users/01', ['active' => false])->status);
    },
    'Rechte: nur admin verwaltet Benutzer, Protokoll lesen admin und reviewer' => function () use ($setup, $send, $get): void {
        $env = $setup();
        $adminId = $env->idOf('admin');
        foreach (['trainer', 'kaderpflege', 'pruefer', 'nada', 'basis', 'ohne-rolle', 'trainer-kaderpflege'] as $person) {
            [$router, $token] = $env->as($person);
            assertSame(403, $get($router, '/api/users')->status, $person . ' GET users');
            assertSame(403, $send($router, $token, 'PUT', '/api/users/' . $adminId . '/roles', ['roles' => []])->status, $person . ' PUT roles');
            assertSame(403, $send($router, $token, 'PUT', '/api/users/' . $adminId, ['active' => false])->status, $person . ' PUT active');
        }
        foreach (['trainer' => 403, 'kaderpflege' => 403, 'nada' => 403, 'basis' => 403, 'ohne-rolle' => 403, 'pruefer' => 200, 'admin' => 200] as $person => $status) {
            [$router] = $env->as($person);
            assertSame($status, $get($router, '/api/audit')->status, $person . ' GET audit');
        }
        assertSame(['admin'], (new UserRepo($env->pdo))->findById($adminId)->roles, 'admin unverändert');
    },
    'ohne Anmeldung 401, ohne CSRF-Token 403' => function () use ($setup, $send, $get): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$anon] = $env->login('unbekannt');
        assertSame(401, $get($anon, '/api/users')->status);
        assertSame(401, $get($anon, '/api/audit')->status);
        [$router] = $env->as('admin');
        $before = $env->snapshot();
        assertSame(403, $send($router, null, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['admin']])->status);
        assertSame(403, $send($router, 'falsch', 'PUT', '/api/users/' . $coachId, ['active' => false])->status);
        assertSame($before, $env->snapshot());
    },
    'Benutzer werden nie gelöscht: DELETE ergibt 405' => function () use ($setup, $send): void {
        $env = $setup();
        [$router, $token, $adminId] = $env->as('admin');
        assertSame(405, $send($router, $token, 'DELETE', '/api/users/' . $adminId, null)->status);
    },
    'GET /api/audit: neueste zuerst, wer (Name), wann (UTC), was, Objekt' => function () use ($setup, $send, $get): void {
        $env = $setup();
        $coachId = $env->idOf('trainer');
        [$router, $token, $adminId] = $env->as('admin');
        $send($router, $token, 'PUT', '/api/users/' . $coachId . '/roles', ['roles' => ['basic']]);
        $res = $get($router, '/api/audit');
        assertSame(200, $res->status);
        $entries = $res->body['entries'];
        assertSame(3, count($entries), 'zwei Personen angelegt, eine Rollenänderung');
        $first = $entries[0];
        assertSame(['id', 'at', 'user_id', 'user_name', 'action', 'object_type', 'object_id', 'summary'], array_keys($first));
        assertSame('roles', $first['action']);
        assertSame($adminId, $first['user_id']);
        assertSame('Ada Verwaltung', $first['user_name']);
        assertSame('user', $first['object_type']);
        assertSame($coachId, $first['object_id']);
        assertSame('roles: basic', $first['summary']);
        assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['at']) === 1, 'ISO 8601 in UTC: ' . $first['at']);
        assertTrue(abs(strtotime($first['at']) - time()) < 300, 'Zeitstempel in UTC');
        assertSame('user_created', $entries[2]['action']);
        assertSame(null, $entries[2]['user_id'], 'Anlegen bei der Anmeldung: ohne handelnde Person');
        assertSame(null, $entries[2]['user_name']);
    },
    'GET /api/audit?limit=N: begrenzt, ungültige Werte 422' => function () use ($setup, $get): void {
        $env = $setup();
        $env->idOf('trainer');
        $env->idOf('basis');
        [$router] = $env->as('admin');
        $res = $get($router, '/api/audit', ['limit' => '2']);
        assertSame(200, $res->status);
        assertSame(2, count($res->body['entries']));
        assertSame('user_created', $res->body['entries'][0]['action']);
        assertTrue($res->body['entries'][0]['id'] > $res->body['entries'][1]['id'], 'neueste zuerst');
        foreach (['0', '-1', 'abc', '2.5', '1001', ''] as $bad) {
            assertSame(422, $get($router, '/api/audit', ['limit' => $bad])->status, 'limit=' . $bad);
        }
        assertSame(422, $get($router, '/api/audit', ['limit' => ['1']])->status, 'limit als Liste');
        assertSame(200, $get($router, '/api/audit', ['limit' => '1000'])->status);
    },
];

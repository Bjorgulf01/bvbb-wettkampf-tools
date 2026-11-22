<?php
declare(strict_types=1);

use App\Api\AuthController;
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
use App\UnsafeConfig;

require_once __DIR__ . '/support/db.php';

/**
 * Baut die Anwendung für einen Test: Router mit Wächter, Auth-Endpunkten und zwei Testrouten.
 * $store ist der Sitzungsspeicher (wie $_SESSION), $now[0] die aktuelle Zeit.
 */
$app = static function (PDO $pdo, array $config, array &$store, array &$now): Router {
    $cfg = new Config($config + ['env' => 'development', 'test_auth' => true, 'session_max_age' => 3600]);
    $clock = static function () use (&$now): int {
        return $now[0];
    };
    $session = new Session($store);
    $users = new UserRepo($pdo);
    $guard = new AuthGuard($session, $users, (int) $cfg->get('session_max_age'), $clock);
    $router = new Router($guard, static function (string $line): void {
    });
    $provider = $cfg->get('test_auth') === true ? new TestAuthProvider(TestAuthProvider::defaultPersonas()) : null;
    (new AuthController($session, $users, $guard, $provider, $cfg, $clock, Permissions::fromDb($pdo)))->routes($router);
    $router->add('GET', '/api/secret', fn() => Response::json(['secret' => true]));
    $router->add('POST', '/api/change', fn() => Response::json(['changed' => true]));
    return $router;
};

$migrated = static function (): PDO {
    $pdo = testPdo();
    (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
    return $pdo;
};

$json = static fn(string $method, string $path, array $body = [], array $headers = []): Request =>
    Request::fromParts($method, $path, [], $headers + ['Content-Type' => 'application/json'], $body === [] ? '' : json_encode($body));

$login = static function (Router $router, string $person) use ($json): Response {
    return $router->dispatch($json('POST', '/api/auth/test-login', ['person' => $person]));
};

return [
    'assertSafe verbietet die Testanmeldung im Echtbetrieb' => function (): void {
        assertThrows(fn() => (new Config(['env' => 'production', 'test_auth' => true]))->assertSafe(), UnsafeConfig::class);
        assertThrows(fn() => (new Config(['env' => 'production', 'test_auth' => 1]))->assertSafe(), UnsafeConfig::class);
        assertThrows(fn() => (new Config(['env' => 'production', 'test_auth' => 'ja']))->assertSafe(), UnsafeConfig::class);
        (new Config(['env' => 'development', 'test_auth' => true]))->assertSafe();
        (new Config(['env' => 'staging', 'test_auth' => true]))->assertSafe();
        (new Config(['env' => 'production', 'test_auth' => false]))->assertSafe();
        (new Config(['env' => 'production']))->assertSafe();
        assertTrue(true);
    },
    'assertSafe verlangt eine bekannte Umgebung' => function (): void {
        assertThrows(fn() => (new Config([]))->assertSafe(), UnsafeConfig::class);
        assertThrows(fn() => (new Config(['env' => 'Production']))->assertSafe(), UnsafeConfig::class);
        assertThrows(fn() => (new Config(['env' => 'prod', 'test_auth' => true]))->assertSafe(), UnsafeConfig::class);
    },
    'Session: Anmeldung, Token, Abmeldung, Ablauf' => function (): void {
        $store = [];
        $renewed = 0;
        $s = new Session($store, function () use (&$renewed): void {
            $renewed++;
        });
        assertSame(null, $s->userId());
        $before = $s->csrfToken();
        assertSame($before, $s->csrfToken(), 'Token bleibt in der Sitzung gleich');
        assertSame(64, strlen($before));
        $s->login(7, 1000);
        assertSame(1, $renewed, 'Sitzungs-ID wird bei der Anmeldung erneuert');
        assertSame(7, $s->userId());
        $token = $s->csrfToken();
        assertTrue($token !== $before, 'neues Token nach der Anmeldung');
        assertSame(true, $s->verifyCsrf($token));
        assertSame(false, $s->verifyCsrf($before));
        assertSame(false, $s->verifyCsrf(''));
        assertSame(false, $s->isExpired(1000 + 3600, 3600));
        assertSame(true, $s->isExpired(1000 + 3601, 3600));
        $s->logout();
        assertSame(null, $s->userId());
        assertSame([], $store, 'Speicher ist leer');
        assertSame(false, (new Session($store))->verifyCsrf('x'), 'ohne Token in der Sitzung nie gültig');
    },
    'Session: manipulierte Werte im Speicher' => function (): void {
        $store = ['uid' => 'abc', 'csrf' => ['x']];
        $s = new Session($store);
        assertSame(null, $s->userId());
        assertSame(false, $s->verifyCsrf('x'));
        assertSame(true, $s->isExpired(5, 3600), 'ohne Anmeldezeit abgelaufen');
    },
    'ohne Sitzung: 401 unauthenticated' => function () use ($app, $migrated): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $res = $app($pdo, [], $store, $now)->dispatch(new Request('GET', '/api/me'));
        assertSame(401, $res->status);
        assertSame(['error' => 'unauthenticated'], $res->body);
        assertSame(401, $app($pdo, [], $store, $now)->dispatch(new Request('GET', '/api/secret'))->status);
    },
    'abgelaufene Sitzung: 401, nicht 500' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $router = $app($pdo, [], $store, $now);
        assertSame(200, $login($router, 'trainer')->status);
        assertSame(200, $router->dispatch(new Request('GET', '/api/me'))->status);
        $now[0] = 1000 + 3601;
        $res = $router->dispatch(new Request('GET', '/api/me'));
        assertSame(401, $res->status);
        assertSame(['error' => 'session_expired'], $res->body);
        assertSame(['error' => 'unauthenticated'], $router->dispatch(new Request('GET', '/api/me'))->body, 'Sitzung ist danach beendet');
    },
    'CSRF: ohne oder mit falschem Token 403, mit Token läuft der Handler' => function () use ($app, $migrated, $login, $json): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $router = $app($pdo, [], $store, $now);
        $token = $login($router, 'kaderpflege')->body['csrf'];
        $res = $router->dispatch($json('POST', '/api/change', ['a' => 1]));
        assertSame(403, $res->status);
        assertSame(['error' => 'csrf'], $res->body);
        assertSame(403, $router->dispatch($json('POST', '/api/change', ['a' => 1], ['X-CSRF-Token' => 'falsch']))->status);
        $ok = $router->dispatch($json('POST', '/api/change', ['a' => 1], ['X-CSRF-Token' => $token]));
        assertSame(200, $ok->status);
        assertSame(['changed' => true], $ok->body);
        assertSame(200, $router->dispatch(new Request('GET', '/api/secret'))->status, 'GET braucht kein Token');
    },
    'abgelaufene Sitzung ohne Token: 401 vor 403' => function () use ($app, $migrated, $login, $json): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $router = $app($pdo, [], $store, $now);
        $login($router, 'kaderpflege');
        $now[0] = 999999;
        assertSame(401, $router->dispatch($json('POST', '/api/change', ['a' => 1]))->status);
    },
    'inaktive Person: 403 inactive' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $router = $app($pdo, [], $store, $now);
        $login($router, 'trainer');
        $pdo->exec("UPDATE users SET active = 0 WHERE subject = 'test:trainer'");
        $res = $router->dispatch(new Request('GET', '/api/me'));
        assertSame(403, $res->status);
        assertSame(['error' => 'inactive'], $res->body);

        $store2 = [];
        $res = $login($app($pdo, [], $store2, $now), 'trainer');
        assertSame(403, $res->status, 'inaktive Person kann sich nicht anmelden');
        assertSame(['error' => 'inactive'], $res->body);
        assertSame(null, (new Session($store2))->userId());
    },
    'Test-Login: erster Admin aus der Konfiguration, sonst nur Test-Rollen' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $res = $login($app($pdo, ['first_admin_subject' => 'test:trainer'], $store, $now), 'trainer');
        assertSame(200, $res->status);
        assertSame(['admin', 'coach'], $res->body['roles']);
        assertSame('Tina Training', $res->body['name']);
        assertSame(['id', 'name', 'roles', 'csrf', 'permissions', 'write_fields'], array_keys($res->body));

        $store = [];
        $res = $login($app($pdo, ['first_admin_subject' => 'test:trainer'], $store, $now), 'pruefer');
        assertSame(['reviewer'], $res->body['roles']);

        $store = [];
        $res = $login($app($pdo, [], $store, $now), 'trainer-kaderpflege');
        assertSame(['coach', 'roster_editor'], $res->body['roles'], 'mehrere Rollen');
    },
    'Rollen nur beim ersten Anlegen' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $login($app($pdo, [], $store, $now), 'trainer');
        $pdo->exec("DELETE ur FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.subject = 'test:trainer'");
        $store = [];
        $res = $login($app($pdo, ['first_admin_subject' => 'test:trainer'], $store, $now), 'trainer');
        assertSame([], $res->body['roles'], 'weder Test-Rollen noch Admin werden erneut vergeben');
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE subject = 'test:trainer'")->fetchColumn());
    },
    '/api/me nennt die Rechte je Gruppe und die schreibbaren Felder' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $now = [1000];
        $expect = [
            'trainer' => [['clubs' => 'read', 'player.basic' => 'read', 'player.health_light' => 'read', 'player.roster' => 'read'], []],
            'basis' => [['clubs' => 'read', 'player.basic' => 'read'], []],
            'admin' => [['audit' => 'read', 'clubs' => 'write', 'player.basic' => 'write', 'player.health_light' => 'read',
                'player.private' => 'write', 'player.roster' => 'write', 'users' => 'write'], null],
        ];
        foreach ($expect as $person => [$groups, $write]) {
            $store = [];
            $router = $app($pdo, [], $store, $now);
            $login($router, $person);
            $res = $router->dispatch(new Request('GET', '/api/me'));
            assertSame($groups, $res->body['permissions'], $person);
            if ($write !== null) {
                assertSame($write, $res->body['write_fields'], $person);
            }
        }
        $fields = $res->body['write_fields'];
        assertTrue(in_array('last_name', $fields, true) && in_array('sportmed_note', $fields, true), 'admin schreibt Name und Notiz');
        foreach (['club_short', 'state', 'sportmed_status', 'antidoping_status'] as $derived) {
            assertTrue(!in_array($derived, $fields, true), $derived . ' ist abgeleitet und nicht schreibbar');
        }
        $store = [];
        $router = $app($pdo, [], $store, $now);
        $login($router, 'ohne-rolle');
        $res = $router->dispatch(new Request('GET', '/api/me'));
        assertSame('{}', json_encode($res->body['permissions']), 'ohne Rolle: leeres Objekt');
        assertSame([], $res->body['write_fields']);
    },
    'Person ohne Rollen: /api/me mit leerer Rollenliste und 200' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $router = $app($pdo, [], $store, $now);
        $token = $login($router, 'ohne-rolle')->body['csrf'];
        $res = $router->dispatch(new Request('GET', '/api/me'));
        assertSame(200, $res->status);
        assertSame([], $res->body['roles']);
        assertSame($token, $res->body['csrf']);
        assertSame('[]', json_encode($res->body['roles']), 'Rollen als JSON-Liste');
    },
    'Test-Login: nur wenn eingeschaltet, unbekannte Person 400' => function () use ($app, $migrated, $login, $json): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        assertSame(404, $login($app($pdo, ['test_auth' => false], $store, $now), 'admin')->status);
        $router = $app($pdo, [], $store, $now);
        $res = $login($router, 'gibt-es-nicht');
        assertSame(400, $res->status);
        assertSame(['error' => 'unknown_person'], $res->body);
        assertSame(400, $router->dispatch($json('POST', '/api/auth/test-login', ['person' => ['admin']]))->status);
        assertSame(400, $router->dispatch($json('POST', '/api/auth/test-login'))->status, 'ohne Body');
        assertSame(null, (new Session($store))->userId());
    },
    'Testpersonen für die Anmeldeseite: nur bei Testanmeldung, ohne E-Mail, ohne Sitzung erreichbar' => function () use ($app, $migrated): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $res = $app($pdo, [], $store, $now)->dispatch(new Request('GET', '/api/auth/test-personas'));
        assertSame(200, $res->status);
        assertSame(['key' => 'admin', 'name' => 'Ada Verwaltung', 'roles' => ['admin']], $res->body['personas'][0]);
        assertSame(count(TestAuthProvider::defaultPersonas()), count($res->body['personas']));
        assertTrue(!str_contains(json_encode($res->body), '@'), 'keine E-Mail-Adressen');
        assertSame(404, $app($pdo, ['test_auth' => false], $store, $now)->dispatch(new Request('GET', '/api/auth/test-personas'))->status);
        assertSame(404, $app($pdo, ['env' => 'production'], $store, $now)->dispatch(new Request('GET', '/api/auth/test-personas'))->status);
    },
    'Abmelden beendet die Sitzung' => function () use ($app, $migrated, $login, $json): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $router = $app($pdo, [], $store, $now);
        $token = $login($router, 'basis')->body['csrf'];
        assertSame(403, $router->dispatch($json('POST', '/api/auth/logout'))->status, 'Abmelden braucht das Token');
        assertSame(200, $router->dispatch($json('POST', '/api/auth/logout', [], ['X-CSRF-Token' => $token]))->status);
        assertSame(401, $router->dispatch(new Request('GET', '/api/me'))->status);
    },
    'Sitzung für einen unbekannten Benutzer: 401' => function () use ($app, $migrated): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        (new Session($store))->login(4711, 1000);
        assertSame(401, $app($pdo, [], $store, $now)->dispatch(new Request('GET', '/api/me'))->status);
    },
    'Protokoll beim Anlegen nennt Rollen, nicht Name oder E-Mail' => function () use ($app, $migrated, $login): void {
        $pdo = $migrated();
        $now = [1000];
        $store = [];
        $id = $login($app($pdo, [], $store, $now), 'trainer-kaderpflege')->body['id'];
        $rows = $pdo->query('SELECT user_id, action, object_type, object_id, summary FROM audit_log')->fetchAll(PDO::FETCH_ASSOC);
        assertSame(1, count($rows));
        assertSame('user_created', $rows[0]['action']);
        assertSame('user', $rows[0]['object_type']);
        assertSame($id, (int) $rows[0]['object_id']);
        assertSame('roles: coach, roster_editor', $rows[0]['summary']);
        $store = [];
        $login($app($pdo, [], $store, $now), 'trainer-kaderpflege');
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'zweite Anmeldung ohne Eintrag');
    },
    'Testanmeldung ist im Echtbetrieb nie eingeschaltet, auch ohne assertSafe' => function () use ($app, $migrated, $login): void {
        assertSame(false, (new Config(['env' => 'production', 'test_auth' => true]))->testAuthEnabled());
        assertSame(false, (new Config(['test_auth' => true]))->testAuthEnabled(), 'ohne Umgebung');
        assertSame(true, (new Config(['env' => 'staging', 'test_auth' => true]))->testAuthEnabled());
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        assertSame(404, $login($app($pdo, ['env' => 'production'], $store, $now), 'admin')->status);
    },
    'Sitzungsdauer muss positiv sein' => function () use ($migrated): void {
        $pdo = $migrated();
        $store = [];
        assertThrows(fn() => new App\Auth\AuthGuard(new Session($store), new UserRepo($pdo), 0), InvalidArgumentException::class);
        assertThrows(fn() => (new Config(['env' => 'development', 'session_max_age' => 0]))->assertSafe(), UnsafeConfig::class);
        assertThrows(fn() => (new Config(['env' => 'development', 'session_max_age' => '3600']))->assertSafe(), UnsafeConfig::class);
        (new Config(['env' => 'development', 'session_max_age' => 3600]))->assertSafe();
    },
    'Abmelden erneuert die Sitzungs-ID' => function (): void {
        $store = [];
        $renewed = 0;
        $s = new Session($store, function () use (&$renewed): void {
            $renewed++;
        });
        $s->login(1, 10);
        $s->logout();
        assertSame(2, $renewed);
    },
    'Abmelden nach abgelaufener Sitzung gelingt ohne Token' => function () use ($app, $migrated, $login, $json): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $router = $app($pdo, [], $store, $now);
        $token = $login($router, 'basis')->body['csrf'];
        $now[0] = 999999;
        assertSame(401, $router->dispatch(new Request('GET', '/api/me'))->status);
        $res = $router->dispatch($json('POST', '/api/auth/logout', [], ['X-CSRF-Token' => $token]));
        assertSame(200, $res->status, 'keine Sitzung mehr: Abmelden ist schon erledigt');
    },
    'currentUser gilt nur für die laufende Anfrage' => function () use ($migrated, $login): void {
        $pdo = $migrated();
        $store = [];
        $now = [1000];
        $session = new Session($store);
        $users = new UserRepo($pdo);
        $guard = new AuthGuard($session, $users, 3600, fn() => $now[0]);
        $router = new Router($guard);
        $cfg = new Config(['env' => 'development', 'test_auth' => true]);
        (new AuthController($session, $users, $guard, new TestAuthProvider(TestAuthProvider::defaultPersonas()), $cfg, fn() => $now[0]))->routes($router);
        $seen = 'nicht aufgerufen';
        $router->add('GET', '/api/public', function () use ($guard, &$seen): Response {
            $seen = $guard->currentUser();
            return Response::json([]);
        }, ['auth' => false]);
        $login($router, 'trainer');
        assertSame(200, $router->dispatch(new Request('GET', '/api/me'))->status);
        $router->dispatch(new Request('GET', '/api/public'));
        assertSame(null, $seen, 'öffentliche Route sieht keinen Benutzer der vorigen Anfrage');
    },
    'inaktive Person: Daten werden bei der Anmeldung nicht überschrieben' => function () use ($migrated): void {
        $pdo = $migrated();
        $users = new UserRepo($pdo);
        $u = $users->upsert(new App\Auth\Identity('sub-1', 'Erika Beispiel', 'erika@example.org'));
        $pdo->exec('UPDATE users SET active = 0 WHERE id = ' . $u->id);
        $again = $users->upsert(new App\Auth\Identity('sub-1', 'Anderer Name', 'anders@example.org'));
        assertSame('Erika Beispiel', $again->name);
        assertSame(false, $again->active);
    },
    'zu lange Namen und E-Mail-Adressen führen nicht zum Serverfehler' => function () use ($migrated): void {
        $pdo = $migrated();
        $users = new UserRepo($pdo);
        $u = $users->upsert(new App\Auth\Identity('sub-2', str_repeat('ä', 250), str_repeat('a', 200) . '@example.org'));
        assertSame(190, mb_strlen($u->name), 'Name gekürzt');
        assertSame(null, $u->email, 'ungültig lange E-Mail wird nicht gespeichert');
        assertThrows(fn() => $users->upsert(new App\Auth\Identity(str_repeat('s', 191), 'X', 'x@example.org')), InvalidArgumentException::class);
        assertThrows(fn() => $users->upsert(new App\Auth\Identity('', 'X', 'x@example.org')), InvalidArgumentException::class);
        assertThrows(fn() => $users->upsert(new App\Auth\Identity('kennung-mit-ü', 'X', 'x@example.org')), InvalidArgumentException::class);
    },
    'Testpersonen sind erfunden und haben eindeutige Kennungen' => function (): void {
        $personas = TestAuthProvider::defaultPersonas();
        foreach (['admin', 'kaderpflege', 'nada', 'pruefer', 'trainer', 'basis', 'trainer-kaderpflege', 'ohne-rolle'] as $key) {
            assertTrue(isset($personas[$key]), $key);
            assertTrue(str_ends_with($personas[$key][1], '@example.org'), $key . ': E-Mail-Adresse aus example.org');
        }
        $p = new TestAuthProvider($personas);
        $id = $p->identify(new Request('POST', '/x', [], [], ['person' => 'nada']));
        assertSame('test:nada', $id->subject);
        assertSame(['health_staff'], $p->rolesOf($id));
        assertSame(null, $p->identify(new Request('POST', '/x', [], [], ['person' => 'x'])));
        assertSame(null, $p->identify(new Request('POST', '/x')));
    },
];

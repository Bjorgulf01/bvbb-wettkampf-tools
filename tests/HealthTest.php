<?php
declare(strict_types=1);

use App\Api\HealthController;
use App\Http\Request;
use App\Http\Router;
use App\Version;

require_once __DIR__ . '/support/db.php';

/** Router nur mit dem Health-Check; $connect liefert die Datenbankverbindung (oder wirft). */
$router = static function (callable $connect): Router {
    $router = new Router(null, static function (string $line): void {
    });
    (new HealthController($connect))->routes($router);
    return $router;
};

return [
    'GET /api/health: ok und Version, keine weiteren Schlüssel, ohne Anmeldung' => function () use ($router): void {
        $pdo = testPdo();
        $res = $router(fn(): PDO => $pdo)->dispatch(new Request('GET', '/api/health'));
        assertSame(200, $res->status);
        assertSame(['ok' => true, 'version' => '1.2.0'], $res->body);
        assertSame('1.2.0', Version::NUMBER);
    },
    'Datenbankfehler: 503 mit {"ok":false}, keine Details' => function () use ($router): void {
        $res = $router(static function (): PDO {
            throw new PDOException('SQLSTATE[HY000] [1045] Access denied for user geheim@host');
        })->dispatch(new Request('GET', '/api/health'));
        assertSame(503, $res->status);
        assertSame(['ok' => false], $res->body);
        assertSame('{"ok":false}', $res->encode());
    },
    'Datenbank antwortet nicht wie erwartet: 503' => function () use ($router): void {
        $res = $router(static function (): PDO {
            throw new RuntimeException('irgendwas');
        })->dispatch(new Request('GET', '/api/health'));
        assertSame(503, $res->status);
        assertSame(['ok' => false], $res->body);
    },
    'nur GET: POST ergibt 405' => function () use ($router): void {
        $res = $router(static fn(): PDO => throw new LogicException('darf nicht aufgerufen werden'))
            ->dispatch(Request::fromParts('POST', '/api/health', [], ['Content-Type' => 'application/json'], '{}'));
        assertSame(405, $res->status);
    },
];

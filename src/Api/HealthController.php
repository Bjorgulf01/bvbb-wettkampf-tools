<?php
declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Version;
use Closure;
use PDO;
use Throwable;

/**
 * GET /api/health ohne Anmeldung: {"ok":true,"version":"1.2.0"}. Kann die Datenbank nicht erreicht
 * werden, 503 mit {"ok":false}, nie mit Details (Fehlertexte können Zugangsdaten oder Hostnamen enthalten).
 * Die Verbindung wird erst hier aufgebaut, damit ein Datenbankfehler nicht schon vorher zu einer 500 führt.
 */
final class HealthController
{
    private Closure $connect;

    /** @param callable(): PDO $connect */
    public function __construct(callable $connect)
    {
        $this->connect = Closure::fromCallable($connect);
    }

    public function routes(Router $router): void
    {
        $router->add('GET', '/api/health', $this->health(...), ['auth' => false, 'csrf' => false]);
    }

    private function health(Request $r, array $params): Response
    {
        try {
            $ok = (int) ($this->connect)()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            $ok = false;
        }
        return $ok
            ? Response::json(['ok' => true, 'version' => Version::NUMBER])
            : Response::json(['ok' => false], 503);
    }
}

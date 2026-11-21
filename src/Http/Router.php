<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthGuard;
use Closure;
use LogicException;
use Throwable;

/**
 * Ordnet Methode und Pfad einem Handler zu. Muster mit Platzhaltern wie /api/players/{id}.
 *
 * Sicherer Standard: Jede Route verlangt eine Anmeldung (auth) und bei POST, PUT, PATCH, DELETE
 * ein CSRF-Token (csrf), außer es wird ausdrücklich abgeschaltet. Der Wächter (Task 7) wird vor
 * dem Handler gefragt. Fehlt er für eine solche Route, scheitert schon add().
 * Fehler im Handler ergeben 500 {"error":"server_error"}; der Text der Ausnahme verlässt den Server nie
 * und steht auch nicht im Protokoll (er kann Personendaten enthalten).
 */
final class Router
{
    /** HEAD wird bewusst nicht unterstützt (ergibt 405). */
    private const SAFE_METHODS = ['GET', 'OPTIONS'];

    /** HTTP-Status je Fehler im Body der Anfrage. */
    private const BODY_STATUS = [
        Request::BODY_INVALID_JSON => 400,
        Request::BODY_UNSUPPORTED_MEDIA_TYPE => 415,
        Request::BODY_TOO_LARGE => 413,
    ];

    /** @var list<array{method:string, pattern:string, regex:string, handler:callable, opts:array{auth:bool, csrf:bool}}> */
    private array $routes = [];

    private Closure $log;

    /** @param (callable(string): void)|null $log Protokoll für Serverfehler, Standard error_log */
    public function __construct(private ?AuthGuard $guard = null, ?callable $log = null)
    {
        $this->log = $log !== null ? Closure::fromCallable($log) : static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param array{auth?:bool, csrf?:bool} $opts
     */
    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $method = strtoupper($method);
        $opts = [
            'auth' => (bool) ($opts['auth'] ?? true),
            'csrf' => (bool) ($opts['csrf'] ?? !in_array($method, self::SAFE_METHODS, true)),
        ];
        if (($opts['auth'] || $opts['csrf']) && $this->guard === null) {
            throw new LogicException('Route ' . $method . ' ' . $pattern . ' braucht einen Wächter (auth/csrf)');
        }
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => self::compile($pattern),
            'handler' => $handler,
            'opts' => $opts,
        ];
    }

    public function dispatch(Request $r): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $r->path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $r->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }
            foreach ($params as $value) {
                // Kein ungültiges UTF-8, keine Steuerzeichen und kein kodierter Schrägstrich in Platzhaltern.
                if (!mb_check_encoding($value, 'UTF-8') || preg_match('#[\x00-\x1F\x7F/]#', $value) === 1) {
                    return Response::json(['error' => 'not_found'], 404);
                }
            }
            return $this->run($route, $r, $params);
        }
        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            sort($allowed);
            return Response::json(['error' => 'method_not_allowed'], 405, ['Allow' => implode(', ', $allowed)]);
        }
        return Response::json(['error' => 'not_found'], 404);
    }

    /** @param array<string, string> $params */
    private function run(array $route, Request $r, array $params): Response
    {
        try {
            // Der Wächter wird bei jeder Anfrage gefragt, auch bei öffentlichen Routen:
            // so gilt currentUser() nie aus einer vorigen Anfrage weiter.
            if ($this->guard !== null) {
                $denied = $this->guard->check($r, $route['opts']);
                if ($denied !== null) {
                    return $denied;
                }
            }
            if ($r->bodyError !== null) {
                return Response::json(['error' => $r->bodyError], self::BODY_STATUS[$r->bodyError] ?? 400);
            }
            $response = ($route['handler'])($r, $params);
            if (!$response instanceof Response) {
                throw new LogicException('Handler hat keine Response geliefert');
            }
            return $response;
        } catch (Throwable $e) {
            ($this->log)(sprintf(
                'Serverfehler %s %s: %s in %s:%d (Code %s)',
                $route['method'],
                $route['pattern'], // Muster statt Pfad: Pfadwerte können Personendaten enthalten
                $e::class,
                basename($e->getFile()),
                $e->getLine(),
                (string) $e->getCode()
            ));
            return Response::json(['error' => 'server_error'], 500);
        }
    }

    /**
     * Wandelt /api/players/{id} in einen regulären Ausdruck; ein Platzhalter passt auf ein Pfadstück.
     * @throws LogicException bei doppeltem Platzhalter
     */
    private static function compile(string $pattern): string
    {
        $parts = preg_split('/(\{[a-z_][a-z0-9_]*\})/i', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        $names = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $part, $m) === 1) {
                if (isset($names[$m[1]])) {
                    throw new LogicException('Platzhalter {' . $m[1] . '} doppelt in ' . $pattern);
                }
                $names[$m[1]] = true;
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        // D: $ passt nicht vor einem abschließenden Zeilenumbruch.
        return '#^' . $regex . '$#D';
    }
}

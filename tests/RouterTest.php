<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

/** Router ohne Wächter, Routen ausdrücklich ohne Anmeldung. */
$router = static function (): Router {
    $open = ['auth' => false, 'csrf' => false];
    $r = new Router(null, static function (string $line): void {
    });
    $r->add('GET', '/api/players',fn(Request $q, array $p) => Response::json(['list' => true]), $open);
    $r->add('GET', '/api/players/{id}', fn(Request $q, array $p) => Response::json(['id' => $p['id']]), $open);
    $r->add('PUT', '/api/players/{id}', fn(Request $q, array $p) => Response::json(['put' => $p['id'], 'json' => $q->json]), $open);
    $r->add('GET', '/api/clubs/{club}/aliases/{alias}', fn(Request $q, array $p) => Response::json($p), $open);
    $r->add('GET', '/api/boom', fn() => throw new Exception('geheim'), $open);
    $r->add('GET', '/api/wrong', fn() => 'kein Response', $open);
    return $r;
};

return [
    'Platzhalter im Pfad' => function () use ($router): void {
        $res = $router()->dispatch(new Request('GET', '/api/players/7'));
        assertSame(200, $res->status);
        assertSame(['id' => '7'], $res->body);
        $res = $router()->dispatch(new Request('GET', '/api/clubs/3/aliases/TSV%20Spandau'));
        assertSame(['club' => '3', 'alias' => 'TSV Spandau'], $res->body, 'mehrere Platzhalter, dekodiert');
    },
    'Pfad ohne Platzhalter und Abfrageteil' => function () use ($router): void {
        assertSame(['list' => true], $router()->dispatch(new Request('GET', '/api/players'))->body);
        assertSame(200, $router()->dispatch(new Request('GET', '/api/players?x=1'))->status, 'Abfrageteil wird ignoriert');
    },
    'unbekannter Pfad ergibt 404' => function () use ($router): void {
        $res = $router()->dispatch(new Request('GET', '/api/nope'));
        assertSame(404, $res->status);
        assertSame(['error' => 'not_found'], $res->body);
        assertSame(404, $router()->dispatch(new Request('GET', '/api/players/7/extra'))->status);
        assertSame(404, $router()->dispatch(new Request('GET', '/api/players/'))->status, 'leerer Platzhalter');
    },
    'falsche Methode ergibt 405 mit Allow' => function () use ($router): void {
        $res = $router()->dispatch(new Request('DELETE', '/api/players/7'));
        assertSame(405, $res->status);
        assertSame(['error' => 'method_not_allowed'], $res->body);
        assertSame('GET, PUT', $res->headers['Allow']);
        assertSame(200, $router()->dispatch(new Request('get', '/api/players/7'))->status, 'Methode ohne Großschreibung');
    },
    'Ausnahme im Handler ergibt 500 ohne den Text' => function () use ($router): void {
        $res = $router()->dispatch(new Request('GET', '/api/boom'));
        assertSame(500, $res->status);
        assertSame(['error' => 'server_error'], $res->body);
        assertTrue(!str_contains($res->encode(), 'geheim'));
        assertSame(500, $router()->dispatch(new Request('GET', '/api/wrong'))->status, 'Handler ohne Response');

        $log = [];
        $r = new Router(null, function (string $line) use (&$log): void {
            $log[] = $line;
        });
        $r->add('GET', '/api/boom', fn() => throw new RuntimeException('Müßig, geheim'), ['auth' => false]);
        $r->dispatch(new Request('GET', '/api/boom'));
        assertSame(1, count($log), 'Fehler wird protokolliert');
        assertTrue(str_contains($log[0], 'RuntimeException'), 'Art der Ausnahme steht im Protokoll');
        assertTrue(!str_contains($log[0], 'geheim') && !str_contains($log[0], 'Müßig'), 'kein Text der Ausnahme im Protokoll');
    },
    'JSON-Body wird durchgereicht, ungültiger JSON ergibt 400' => function () use ($router): void {
        $res = $router()->dispatch(new Request('PUT', '/api/players/7', [], [], ['last_name' => 'Müßig']));
        assertSame(['put' => '7', 'json' => ['last_name' => 'Müßig']], $res->body);
        $bad = Request::fromParts('PUT', '/api/players/7', [], ['Content-Type' => 'application/json'], '{kaputt');
        $res = $router()->dispatch($bad);
        assertSame(400, $res->status);
        assertSame(['error' => 'invalid_json'], $res->body);
        $list = Request::fromParts('PUT', '/api/players/7', [], ['Content-Type' => 'application/json'], '"nur Text"');
        assertSame(400, $router()->dispatch($list)->status, 'JSON muss ein Objekt oder eine Liste sein');
    },
    'Request: Header ohne Großschreibung, Abfrage, Body' => function (): void {
        $r = Request::fromParts('post', '/api/x?a=1', ['a' => '1'], ['X-CSRF-Token' => 'abc', 'Content-Type' => 'application/json; charset=utf-8'], '{"n":"Köppen"}');
        assertSame('POST', $r->method);
        assertSame('/api/x', $r->path);
        assertSame(['a' => '1'], $r->query);
        assertSame('abc', $r->header('x-csrf-token'));
        assertSame('abc', $r->header('X-Csrf-Token'));
        assertSame(null, $r->header('Authorization'));
        assertSame(['n' => 'Köppen'], $r->json);
        assertSame(null, $r->bodyError);
        $empty = Request::fromParts('POST', '/api/x', [], ['Content-Type' => 'application/json'], '');
        assertSame(null, $empty->json);
        assertSame(null, $empty->bodyError, 'leerer Body ist kein Fehler');
    },
    'Response kodiert Umlaute und setzt Schutz-Header' => function (): void {
        $res = Response::json(['name' => 'Weißkopf'], 201);
        assertSame(201, $res->status);
        assertSame('{"name":"Weißkopf"}', $res->encode());
        assertSame('application/json; charset=utf-8', $res->headers['Content-Type']);
        assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        assertSame('no-store', $res->headers['Cache-Control']);
    },
    'Zeilenumbruch und unzulässige Zeichen im Pfad ergeben 404' => function () use ($router): void {
        assertSame(404, $router()->dispatch(new Request('GET', "/api/players\n"))->status);
        assertSame(404, $router()->dispatch(new Request('GET', '/api/players/7%0A'))->status, 'Steuerzeichen');
        assertSame(404, $router()->dispatch(new Request('GET', '/api/players/%FF'))->status, 'ungültiges UTF-8');
        assertSame(404, $router()->dispatch(new Request('GET', '/api/players/a%2Fb'))->status, 'kodierter Schrägstrich');
        assertSame(['club' => '3', 'alias' => 'Grün-Weiß'], $router()->dispatch(new Request('GET', '/api/clubs/3/aliases/Gr%C3%BCn-Wei%C3%9F'))->body);
    },
    'Body ohne JSON-Content-Type ergibt 415, zu großer Body 413' => function () use ($router): void {
        $form = Request::fromParts('PUT', '/api/players/7', [], ['Content-Type' => 'text/plain'], '{"person":"admin"}');
        $res = $router()->dispatch($form);
        assertSame(415, $res->status);
        assertSame(['error' => 'unsupported_media_type'], $res->body);
        $none = Request::fromParts('PUT', '/api/players/7', [], [], '{"a":1}');
        assertSame(415, $router()->dispatch($none)->status, 'ohne Content-Type');
        $ok = Request::fromParts('PUT', '/api/players/7', [], ['Content-Type' => 'Application/JSON; charset=UTF-8'], '{"a":1}');
        assertSame(200, $router()->dispatch($ok)->status);
        $big = new Request('PUT', '/api/players/7', [], [], null, 'too_large');
        $res = $router()->dispatch($big);
        assertSame(413, $res->status);
        assertSame(['error' => 'too_large'], $res->body);
    },
    'Protokoll nennt das Muster, nicht den konkreten Pfad' => function (): void {
        $log = [];
        $r = new Router(null, function (string $line) use (&$log): void {
            $log[] = $line;
        });
        $r->add('GET', '/api/search/{name}', fn() => throw new RuntimeException('x'), ['auth' => false]);
        $r->dispatch(new Request('GET', '/api/search/M%C3%BC%C3%9Fig'));
        assertTrue(str_contains($log[0], '/api/search/{name}'), $log[0]);
        assertTrue(!str_contains($log[0], 'M%C3') && !str_contains($log[0], 'Müßig'), 'kein Pfadwert im Protokoll');
    },
    'doppelter Platzhalter wird beim Anlegen abgelehnt' => function (): void {
        $r = new Router();
        assertThrows(fn() => $r->add('GET', '/api/a/{id}/b/{id}', fn() => Response::json([]), ['auth' => false]), LogicException::class);
    },
    'Response: Header ohne Großschreibung ersetzen, leerer Body ist ein Objekt' => function (): void {
        $res = Response::json([], 200, ['cache-control' => 'max-age=60']);
        assertSame('max-age=60', $res->headers['Cache-Control']);
        assertSame(1, count(array_filter(array_keys($res->headers), fn($k) => strtolower($k) === 'cache-control')));
        assertSame('{}', $res->encode());
        assertSame('{"list":[]}', Response::json(['list' => []])->encode());
    },
    'HEAD wird nicht unterstützt' => function () use ($router): void {
        assertSame(405, $router()->dispatch(new Request('HEAD', '/api/players'))->status);
    },
    'Route mit Anmeldung ohne Wächter wird beim Anlegen abgelehnt' => function (): void {
        $r = new Router();
        assertThrows(fn() => $r->add('GET', '/api/me', fn() => Response::json([])), LogicException::class);
        assertThrows(fn() => $r->add('POST', '/api/x', fn() => Response::json([]), ['auth' => false]), LogicException::class);
        $r->add('POST', '/api/auth/test-login', fn() => Response::json([]), ['auth' => false, 'csrf' => false]);
        assertSame(200, $r->dispatch(new Request('POST', '/api/auth/test-login'))->status);
    },
];

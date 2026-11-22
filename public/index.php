<?php
declare(strict_types=1);

/*
 * Einstiegspunkt für alle Anfragen an /api/…. Die Oberfläche (index.html, js/, css/, vendor/) liefert
 * der Webserver direkt aus. Erste Fassung aus Task 11 für die lokale Vorschau; Task 12 ergänzt die
 * Auslieferung (Health-Check, Lage von src/ und config.php auf dem Webspace).
 *
 * Konfiguration: Datei aus der Umgebungsvariable BVBB_CONFIG, sonst config.php im Ordner über public/.
 */

use App\Api\AuditController;
use App\Api\AuthController;
use App\Api\ClubsController;
use App\Api\PlayersController;
use App\Api\UsersController;
use App\Auth\AuthGuard;
use App\Auth\Session;
use App\Auth\TestAuthProvider;
use App\Config;
use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rbac\Permissions;
use App\Repo\UserRepo;

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Eingebauter PHP-Server (nur Entwicklung): Dateien direkt ausliefern, Startseite für /.
if (PHP_SAPI === 'cli-server' && !str_starts_with($path, '/api/')) {
    if ($path === '/' || $path === '') {
        $_SERVER['SCRIPT_NAME'] = '/index.html';
        readfile(__DIR__ . '/index.html');
        return true;
    }
    return false;
}

// Nie Fehlertexte an den Browser: Pfade, SQL oder Werte könnten darin stehen. Nur ins Server-Protokoll.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Fatale Fehler (zum Beispiel Speichergrenze) fängt kein catch: dann nur eine allgemeine 500.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo '{"error":"server_error"}';
    }
});

$appDir = dirname(__DIR__);
require $appDir . '/src/autoload.php';

/** Allgemeine Fehlerantwort ohne Details (Text der Ausnahme kann Zugangs- oder Personendaten enthalten). */
$fail = static function (Throwable $e): void {
    error_log('bvbb: ' . get_class($e) . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        Response::json(['error' => 'server_error'], 500)->send();
    }
};

try {
    $configPath = getenv('BVBB_CONFIG');
    $config = Config::fromFile(is_string($configPath) && $configPath !== '' ? $configPath : $appDir . '/config.php');
    $config->assertSafe();
    $env = (string) $config->get('env');
    $maxAge = (int) $config->get('session_max_age', 8 * 3600);

    // Sitzungsdaten mindestens so lange aufheben, wie die Sitzung gilt (PHP-Standard: 24 Minuten).
    ini_set('session.gc_maxlifetime', (string) $maxAge);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $env !== 'development',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('bvbb_session');
    ini_set('session.use_strict_mode', '1');
    session_start();

    $pdo = Db::connect($config);
    $session = new Session($_SESSION);
    $users = new UserRepo($pdo);
    $guard = new AuthGuard($session, $users, $maxAge);
    $permissions = Permissions::fromDb($pdo);
    $router = new Router($guard);

    $testAuth = $config->testAuthEnabled() ? new TestAuthProvider(TestAuthProvider::defaultPersonas()) : null;
    (new AuthController($session, $users, $guard, $testAuth, $config, null, $permissions))->routes($router);
    (new PlayersController($pdo, $permissions, $guard))->routes($router);
    (new ClubsController($pdo, $permissions, $guard))->routes($router);
    (new UsersController($pdo, $permissions, $guard))->routes($router);
    (new AuditController($pdo, $permissions, $guard))->routes($router);

    $response = $router->dispatch(Request::fromGlobals());
    session_write_close();
    $response->send();
} catch (Throwable $e) {
    $fail($e);
}

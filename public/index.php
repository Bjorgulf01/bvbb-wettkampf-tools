<?php
declare(strict_types=1);

/*
 * Einstiegspunkt für alle Anfragen an /api/…. Die Oberfläche (index.html, js/, css/, vendor/) liefert
 * der Webserver direkt aus; .htaccess leitet nur /api/… hierher.
 *
 * Lage des Programms (src/, migrations/, config.php), in dieser Reihenfolge:
 *  1. Umgebungsvariable BVBB_APP_DIR,
 *  2. Ordner bvbb-app/ neben dem öffentlichen Verzeichnis (Webspace: webseiten/ und bvbb-app/ nebeneinander),
 *  3. der Ordner über public/ (Repository, lokale Vorschau).
 * Konfiguration: Datei aus BVBB_CONFIG, sonst config.php im Programmordner.
 */

use App\Api\AuditController;
use App\Api\AuthController;
use App\Api\ClubsController;
use App\Api\HealthController;
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
header_remove('X-Powered-By');

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

$appDir = (static function (): string {
    $env = getenv('BVBB_APP_DIR');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/\\');
    }
    $sibling = dirname(__DIR__) . '/bvbb-app';
    return is_file($sibling . '/src/autoload.php') ? $sibling : dirname(__DIR__);
})();
if (!is_file($appDir . '/src/autoload.php')) {
    error_log('bvbb: Programmordner nicht gefunden');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"server_error"}';
    return;
}
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
    $config->assertSafe(); // vor allem anderen: unsichere Konfiguration ergibt 500, auch beim Health-Check

    // Health-Check vor der Sitzung und vor der Datenbank: Ein Datenbankfehler ergibt hier 503, nicht 500.
    // Geprüft werden Datenbank und (falls konfiguriert) der Sitzungsordner, ohne Details in der Antwort.
    $savePath = $config->get('session_save_path');
    $savePathOk = $savePath === null || (is_string($savePath) && is_dir($savePath) && is_writable($savePath));
    if ($path === '/api/health') {
        $health = new Router(null);
        (new HealthController(static function () use ($config, $savePathOk): PDO {
            if (!$savePathOk) {
                throw new RuntimeException('session_save_path ist kein beschreibbarer Ordner');
            }
            return Db::connect($config);
        }))->routes($health);
        $health->dispatch(Request::fromGlobals())->send();
        return;
    }

    $env = (string) $config->get('env');
    $secure = $env !== 'development';
    $maxAge = (int) $config->get('session_max_age', 8 * 3600);

    // Eigener Ordner für Sitzungsdateien (nicht der gemeinsame des Hosters), wenn konfiguriert.
    if ($savePath !== null) {
        if (!$savePathOk) {
            throw new RuntimeException('session_save_path ist kein beschreibbarer Ordner');
        }
        ini_set('session.save_path', $savePath);
        // Viele Hoster räumen nur ihren Standardordner per Cron auf (gc_probability 0). Im eigenen Ordner
        // räumt PHP deshalb selbst auf: bei etwa jeder hundertsten Anfrage abgelaufene Sitzungsdateien löschen.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    // Sitzungsdaten mindestens so lange aufheben, wie die Sitzung gilt (PHP-Standard: 24 Minuten).
    ini_set('session.gc_maxlifetime', (string) $maxAge);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // ausdrücklich leer: eine Domain aus der php.ini des Hosters machte das __Host-Cookie ungültig
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // __Host-: der Browser nimmt das Cookie nur über HTTPS, ohne Domain und für den ganzen Pfad an.
    session_name($secure ? '__Host-bvbb_session' : 'bvbb_session');
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

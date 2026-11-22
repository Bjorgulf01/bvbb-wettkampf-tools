<?php
declare(strict_types=1);

/*
 * Wendet ausstehende Migrationen an: php bin/migrate.php
 * Nur auf der Kommandozeile (der Webspace hat derzeit kein SSH; dort Migrationen per phpMyAdmin
 * importieren, siehe README.md). Liest config.php im Programmordner oder die Datei aus BVBB_CONFIG.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use App\Config;
use App\Db;
use App\Migrator;

try {
    $path = getenv('BVBB_CONFIG');
    $config = Config::fromFile(is_string($path) && $path !== '' ? $path : __DIR__ . '/../config.php');
    $config->assertSafe();
    $pdo = Db::connect($config);
    $applied = (new Migrator($pdo, __DIR__ . '/../migrations'))->migrate();
    echo $applied === [] ? "Keine ausstehenden Migrationen.\n" : 'Angewendet: ' . implode(', ', $applied) . "\n";
} catch (Throwable $e) {
    // Nur Art und Code des Fehlers (bei der Datenbank der SQLSTATE): Der Text kann Zugangsdaten enthalten.
    fwrite(STDERR, 'Migration fehlgeschlagen: ' . get_class($e) . ' (Code ' . $e->getCode() . ")\n");
    exit(1);
}

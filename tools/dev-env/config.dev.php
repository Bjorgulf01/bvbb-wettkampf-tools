<?php
declare(strict_types=1);

// Konfiguration nur für die lokale Vorschau (docker compose … up web). Enthält keine Zugangsdaten:
// Die Datenbank kommt aus TEST_DB_* (tools/dev-env/.env), also aus der eigenen Test-Datenbank
// dieses Rechners. Ein Testlauf löscht deren Tabellen; danach tools/dev-env/seed-preview.php ausführen.
return [
    'env' => 'development',
    'test_auth' => true,
    'session_max_age' => 8 * 3600,
    'db' => [
        'dsn' => (string) getenv('TEST_DB_DSN'),
        'user' => (string) getenv('TEST_DB_USER'),
        'pass' => (string) getenv('TEST_DB_PASS'),
    ],
];

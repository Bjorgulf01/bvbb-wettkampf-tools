<?php
declare(strict_types=1);

use App\Config;
use App\Db;

/**
 * Liefert eine Verbindung zur Test-Datenbank aus TEST_DB_DSN, TEST_DB_USER, TEST_DB_PASS
 * und löscht vorher ALLE Tabellen dieser Datenbank.
 * Ohne gesetzte Variablen wird der Test übersprungen (zählt nicht als Erfolg).
 * Schutz: Der Name der Datenbank muss mit "bvbb_test" beginnen, sonst wird nichts gelöscht.
 */
function testPdo(): PDO
{
    $dsn = getenv('TEST_DB_DSN');
    if ($dsn === false || $dsn === '') {
        skip('keine Test-Datenbank: TEST_DB_DSN nicht gesetzt');
    }
    $pdo = Db::connect(new Config(['db' => [
        'dsn' => $dsn,
        'user' => (string) getenv('TEST_DB_USER'),
        'pass' => (string) getenv('TEST_DB_PASS'),
    ]]));

    $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!str_starts_with($name, 'bvbb_test')) {
        throw new RuntimeException('Abbruch: Datenbank "' . $name . '" ist keine Test-Datenbank (bvbb_test…)');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as [$table, $type]) {
        $kind = $type === 'VIEW' ? 'VIEW' : 'TABLE';
        $pdo->exec('DROP ' . $kind . ' `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return $pdo;
}

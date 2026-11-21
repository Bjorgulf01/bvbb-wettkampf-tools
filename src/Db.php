<?php
declare(strict_types=1);

namespace App;

use PDO;

/** Baut die Datenbankverbindung (utf8mb4, Ausnahmen, echte vorbereitete Abfragen). */
final class Db
{
    public static function connect(Config $c): PDO
    {
        $dsn = (string) $c->get('db.dsn');
        if (stripos($dsn, 'charset=') === false) {
            $dsn .= (str_ends_with($dsn, ';') ? '' : ';') . 'charset=utf8mb4';
        }
        $pdo = new PDO(
            $dsn,
            (string) $c->get('db.user', ''),
            (string) $c->get('db.pass', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        // Zu lange oder ungültige Werte führen zu einem Fehler statt still abgeschnitten zu werden.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }
}

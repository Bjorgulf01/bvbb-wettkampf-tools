<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Wendet die SQL-Dateien aus migrations/ in Namensreihenfolge an und merkt sie in schema_migrations.
 * Jede Datei trägt sich am Ende selbst ein (damit der Import per phpMyAdmin denselben Stand ergibt).
 * Je Datei läuft eine Transaktion. MariaDB beendet sie bei DDL (CREATE, ALTER) implizit,
 * deshalb schützt sie nur reine Datenänderungen vollständig.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return list<string> neu angewendete Dateinamen */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = $this->pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $done = array_flip($done);

        $files = glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($done[$name])) {
                continue;
            }
            $this->apply($name, (string) file_get_contents($file));
            $applied[] = $name;
        }
        return $applied;
    }

    private function apply(string $name, string $sql): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            // Falls die Datei sich nicht selbst eingetragen hat (oder der Eintrag schon besteht).
            $this->pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zerlegt SQL an Semikolons außerhalb von Strings, Bezeichnern und Kommentaren.
     * Kommentare werden entfernt. Nicht unterstützt: DELIMITER, gespeicherte Prozeduren,
     * ausführbare Kommentare (/*! … *&#47;, ergeben einen Fehler) und NO_BACKSLASH_ESCAPES.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $end = $i + 1;
                while ($end < $len) {
                    if ($sql[$end] === '\\' && $ch !== '`') {
                        $end += 2;
                        continue;
                    }
                    if ($sql[$end] === $ch) {
                        if (($sql[$end + 1] ?? '') === $ch) { // verdoppeltes Anführungszeichen
                            $end += 2;
                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                $buf .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if (($ch === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))
                || $ch === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*' && ($sql[$i + 2] ?? '') === '!') {
                throw new InvalidArgumentException('Ausführbare Kommentare (/*! … */) werden nicht unterstützt');
            }
            if ($ch === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $len : $close + 1;
                $buf .= ' ';
                continue;
            }
            if ($ch === ';') {
                self::push($out, $buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        self::push($out, $buf);
        return $out;
    }

    /** @param list<string> $out */
    private static function push(array &$out, string $statement): void
    {
        $statement = trim($statement);
        if ($statement !== '') {
            $out[] = $statement;
        }
    }
}

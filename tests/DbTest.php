<?php
declare(strict_types=1);

use App\Migrator;

require_once __DIR__ . '/support/db.php';

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

/** Rechte-Matrix aus dem Plan (Task 2 und Task 4b), sortiert nach Rolle und Gruppe. */
function expectedGrants(): array
{
    $matrix = [
        //                       admin    roster   health   review   coach    basic
        'player.basic'        => ['write', 'write', 'read',  'read',  'read',  'read'],
        'player.roster'       => ['write', 'write', 'read',  'read',  'read',  null],
        'player.private'      => ['write', 'write', null,    null,    null,    null],
        'player.health_light' => ['read',  'read',  'read',  'read',  'read',  null],
        'clubs'               => ['write', 'write', 'read',  'read',  'read',  'read'],
        'users'               => ['write', null,    null,    null,    null,    null],
        'audit'               => ['read',  null,    null,    'read',  null,    null],
    ];
    $roles = ['admin', 'roster_editor', 'health_staff', 'reviewer', 'coach', 'basic'];
    $out = [];
    foreach ($matrix as $group => $modes) {
        foreach ($modes as $i => $mode) {
            if ($mode !== null) {
                $out[] = $roles[$i] . ' ' . $group . ' ' . $mode;
            }
        }
    }
    sort($out);
    return $out;
}

return [
    'splitStatements beachtet Strings und Kommentare' => function (): void {
        $sql = "-- Kommentar; mit Semikolon\n"
            . "CREATE TABLE a (x INT); /* block; */\n"
            . "INSERT INTO a VALUES ('Müßig; ''zitiert''');\n"
            . "# noch ein Kommentar;\n"
            . "INSERT INTO a VALUES (\"x;y\")";
        assertSame([
            'CREATE TABLE a (x INT)',
            "INSERT INTO a VALUES ('Müßig; ''zitiert''')",
            'INSERT INTO a VALUES ("x;y")',
        ], Migrator::splitStatements($sql));
    },
    'jede Migrationsdatei trägt sich am Ende selbst ein' => function (): void {
        $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
        assertTrue(count($files) >= 2, 'Migrationsdateien fehlen');
        foreach ($files as $file) {
            $statements = Migrator::splitStatements((string) file_get_contents($file));
            $last = end($statements);
            assertTrue(
                str_contains($last, 'schema_migrations') && str_contains($last, "'" . basename($file) . "'"),
                basename($file) . ': letzte Anweisung trägt die Datei nicht in schema_migrations ein'
            );
        }
    },
    'splitStatements lehnt ausführbare Kommentare ab' => function (): void {
        assertThrows(fn() => Migrator::splitStatements('/*!40101 SET NAMES utf8 */;'), InvalidArgumentException::class);
    },
    'migrate wendet Dateien einmal an' => function (): void {
        $pdo = testPdo();
        $m = new Migrator($pdo, MIGRATIONS_DIR);
        $all = array_map('basename', glob(MIGRATIONS_DIR . '/*.sql'));
        sort($all, SORT_STRING);
        assertSame(['001_core.sql', '002_seed_roles.sql'], array_slice($all, 0, 2));
        assertSame($all, $m->migrate());
        assertSame([], $m->migrate());
        $files = $pdo->query('SELECT filename FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
        sort($files, SORT_STRING);
        assertSame($all, $files);
    },
    'abgebrochene DDL-Migration lässt sich erneut ausführen' => function (): void {
        $pdo = testPdo();
        // Simuliert einen Abbruch mitten in 001: ein Teil der Tabellen existiert schon, nichts ist vermerkt.
        $statements = Migrator::splitStatements((string) file_get_contents(MIGRATIONS_DIR . '/001_core.sql'));
        foreach (array_slice($statements, 0, 4) as $s) {
            $pdo->exec($s);
        }
        $applied = (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        assertSame('001_core.sql', $applied[0]);
    },
    'subject unterscheidet Groß- und Kleinschreibung' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $ins = $pdo->prepare('INSERT INTO users (subject, name) VALUES (?, ?)');
        $ins->execute(['aB12', 'Erika Beispiel']);
        $ins->execute(['ab12', 'Max Muster']);
        $sel = $pdo->prepare('SELECT name FROM users WHERE subject = ?');
        $sel->execute(['ab12']);
        assertSame(['Max Muster'], $sel->fetchAll(PDO::FETCH_COLUMN));
        $sel->execute(['ab12 ']);
        assertSame([], $sel->fetchAll(PDO::FETCH_COLUMN), 'Leerzeichen am Ende zählen');
    },
    'Bundesland nur BER, BRA oder leer, exakt geschrieben' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $ins = $pdo->prepare('INSERT INTO clubs (name, state) VALUES (?, ?)');
        $ins->execute(['Verein A', 'BER']);
        $ins->execute(['Verein B', 'BRA']);
        $ins->execute(['Verein C', null]);
        foreach (['ber', 'Bra', 'XYZ', 'BE'] as $bad) {
            assertThrows(fn() => $ins->execute(['Verein X', $bad]), PDOException::class);
        }
    },
    'zu lange Werte werden abgelehnt statt abgeschnitten' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $ins = $pdo->prepare('INSERT INTO players (last_name, first_name) VALUES (?, ?)');
        assertThrows(fn() => $ins->execute([str_repeat('ä', 101), 'X']), PDOException::class);
    },
    'Verbindung rechnet in UTC (Zeitstempel im Protokoll)' => function (): void {
        $pdo = testPdo();
        assertSame('+00:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn());
        $db = strtotime($pdo->query('SELECT NOW()')->fetchColumn() . ' UTC');
        assertTrue(abs($db - time()) < 300, 'NOW() entspricht der UTC-Zeit');
    },
    'per phpMyAdmin eingespielte Datei wird nicht erneut angewendet' => function (): void {
        $pdo = testPdo();
        // Wie ein Import in phpMyAdmin: die Datei Anweisung für Anweisung ausführen.
        foreach (Migrator::splitStatements((string) file_get_contents(MIGRATIONS_DIR . '/001_core.sql')) as $s) {
            $pdo->exec($s);
        }
        $applied = (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        assertSame('002_seed_roles.sql', $applied[0]);
        assertTrue(!in_array('001_core.sql', $applied, true), '001 nicht erneut angewendet');
    },
    '003: Jahrgang wird einmal an den Geburtstag angeglichen, ohne Protokoll' => function (): void {
        $pdo = testPdo();
        $dir = sys_get_temp_dir() . '/bvbb_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['001_core.sql', '002_seed_roles.sql'] as $f) {
                copy(MIGRATIONS_DIR . '/' . $f, $dir . '/' . $f);
            }
            (new Migrator($pdo, $dir))->migrate();
            $pdo->exec("INSERT INTO players (id, last_name, first_name, club_name, birth_year, active) VALUES
                (1, 'Köppen', 'Arvid', '', 2015, 1),
                (2, 'Müßig', 'Lotta', '', 2008, 1),
                (3, 'Weißkopf', 'Ivo', '', NULL, 1),
                (4, 'Özdemir', 'Mira', '', 2011, 1)");
            $pdo->exec("INSERT INTO player_private (player_id, birth_date) VALUES
                (1, '2014-09-14'), (2, NULL), (3, '2016-03-01')");
            copy(MIGRATIONS_DIR . '/003_birth_year_from_date.sql', $dir . '/003_birth_year_from_date.sql');
            assertSame(['003_birth_year_from_date.sql'], (new Migrator($pdo, $dir))->migrate());
            $years = $pdo->query('SELECT id, birth_year FROM players ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
            assertSame([1 => 2014, 2 => 2008, 3 => 2016, 4 => 2011], array_map('intval', $years),
                'abweichend angeglichen, ohne Datum unverändert, ohne Jahrgang gesetzt, ohne private Zeile unverändert');
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'kein Protokolleintrag');
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE filename = '003_birth_year_from_date.sql'")->fetchColumn());
        } finally {
            array_map('unlink', glob($dir . '/*.sql'));
            rmdir($dir);
        }
    },
    'fehlerhafte Migration wird nicht als angewendet vermerkt' => function (): void {
        $pdo = testPdo();
        $dir = sys_get_temp_dir() . '/bvbb_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/001_ok.sql', 'CREATE TABLE t_ok (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
            file_put_contents($dir . '/002_bad.sql', "INSERT INTO t_ok VALUES (1);\nINSERT INTO gibt_es_nicht VALUES (1);");
            $m = new Migrator($pdo, $dir);
            assertThrows(fn() => $m->migrate(), PDOException::class);
            $files = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            assertSame(['001_ok.sql'], $files);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM t_ok')->fetchColumn(), 'Transaktion zurückgerollt');
        } finally {
            array_map('unlink', glob($dir . '/*.sql'));
            rmdir($dir);
        }
    },
    'Rollen und Rechte nach Plan' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $keys = $pdo->query('SELECT role_key FROM roles ORDER BY role_key')->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['admin', 'basic', 'coach', 'health_staff', 'reviewer', 'roster_editor'], $keys);

        $coachPrivate = $pdo->query(
            "SELECT COUNT(*) FROM role_permissions p JOIN roles r ON r.id = p.role_id
             WHERE r.role_key = 'coach' AND p.perm_group = 'player.private'"
        )->fetchColumn();
        assertSame(0, (int) $coachPrivate, 'coach hat keine Rechte auf player.private');

        $coachLight = $pdo->query(
            "SELECT p.mode FROM role_permissions p JOIN roles r ON r.id = p.role_id
             WHERE r.role_key = 'coach' AND p.perm_group = 'player.health_light'"
        )->fetchColumn();
        assertSame('read', $coachLight, 'coach liest player.health_light');

        $grants = $pdo->query(
            "SELECT CONCAT(r.role_key, ' ', p.perm_group, ' ', p.mode) FROM role_permissions p
             JOIN roles r ON r.id = p.role_id"
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($grants);
        assertSame(expectedGrants(), $grants);
    },
    'Umlaute und ß überstehen Speichern und Laden' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $names = ['Müßig', 'Köppen', 'Weißkopf', 'Möllendorf'];
        $ins = $pdo->prepare('INSERT INTO players (last_name, first_name, club_name) VALUES (?, ?, ?)');
        foreach ($names as $n) {
            $ins->execute([$n, 'Jürgen', 'SV Grün-Weiß Bärenstadt']);
        }
        $rows = $pdo->query('SELECT last_name, first_name, club_name FROM players ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        assertSame($names, array_column($rows, 'last_name'));
        assertSame('Jürgen', $rows[0]['first_name']);
        assertSame('SV Grün-Weiß Bärenstadt', $rows[0]['club_name']);

        $sel = $pdo->prepare('SELECT COUNT(*) FROM players WHERE last_name = ?');
        $sel->execute(['Müßig']);
        assertSame(1, (int) $sel->fetchColumn(), 'Suche mit Umlaut über vorbereitete Abfrage');
    },
    'Tabellen nutzen InnoDB und utf8mb4_unicode_ci' => function (): void {
        $pdo = testPdo();
        (new Migrator($pdo, MIGRATIONS_DIR))->migrate();
        $rows = $pdo->query(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_ASSOC);
        $tables = array_column($rows, 'TABLE_NAME');
        sort($tables, SORT_STRING);
        assertSame([
            'audit_log', 'club_aliases', 'clubs', 'player_private', 'players',
            'role_permissions', 'roles', 'schema_migrations', 'user_roles', 'users',
        ], $tables);
        foreach ($rows as $r) {
            assertSame('InnoDB', $r['ENGINE'], $r['TABLE_NAME']);
            assertSame('utf8mb4_unicode_ci', $r['TABLE_COLLATION'], $r['TABLE_NAME']);
        }
    },
];

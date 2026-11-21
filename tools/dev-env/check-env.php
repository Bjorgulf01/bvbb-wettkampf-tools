<?php
// Prüft die Entwicklungsumgebung: PHP, Erweiterungen und die Test-Datenbank.
// Aufruf: php tools/dev-env/check-env.php
$ok = true;
function line($label, $value, $good = null)
{
    global $ok;
    $mark = $good === null ? '   ' : ($good ? '[OK]' : '[!!]');
    if ($good === false) {
        $ok = false;
    }
    echo str_pad($mark, 6) . str_pad($label, 34) . $value . "\n";
}
line('PHP-Version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='));
foreach (array('pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'openssl', 'ctype', 'hash') as $e) {
    line('Erweiterung ' . $e, extension_loaded($e) ? 'geladen' : 'FEHLT', extension_loaded($e));
}
$dsn = getenv('TEST_DB_DSN');
$user = getenv('TEST_DB_USER');
$pass = getenv('TEST_DB_PASS');
if (!$dsn || !$user) {
    line('Test-Datenbank', 'TEST_DB_DSN oder TEST_DB_USER nicht gesetzt', false);
} else {
    try {
        $pdo = new PDO($dsn, $user, $pass === false ? '' : $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        line('Verbindung', 'hergestellt', true);
        line('Datenbank-Version', (string)$pdo->query('SELECT VERSION()')->fetchColumn());
        $cs = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch(PDO::FETCH_NUM);
        line('Zeichensatz', $cs ? $cs[1] : 'unbekannt', $cs && $cs[1] === 'utf8mb4');
        $t = 'bvbb_env_check';
        $pdo->exec('DROP TABLE IF EXISTS ' . $t);
        $pdo->exec('CREATE TABLE ' . $t . ' (id INT PRIMARY KEY, txt VARCHAR(60) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $st = $pdo->prepare('INSERT INTO ' . $t . ' (id, txt) VALUES (1, ?)');
        $st->execute(array('Müßig Köppen Weißkopf'));
        $back = $pdo->query('SELECT txt FROM ' . $t . ' WHERE id = 1')->fetchColumn();
        line('Umlaute hin und zurück', $back, $back === 'Müßig Köppen Weißkopf');
        $pdo->exec('DROP TABLE ' . $t);
        line('Schreibrechte (CREATE, DROP)', 'ok', true);
    } catch (PDOException $e) {
        line('Datenbank', 'Fehler: ' . $e->getMessage(), false);
    }
}
echo $ok ? "\nAlles in Ordnung.\n" : "\nEs gibt Probleme, siehe [!!].\n";
exit($ok ? 0 : 1);

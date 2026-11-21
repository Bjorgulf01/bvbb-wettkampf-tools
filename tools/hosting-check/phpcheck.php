<?php
/*
 * BVBB Hosting-Check
 *
 * So benutzen:
 *  1. Unten ein eigenes, zufälliges Token eintragen (mindestens 16 Zeichen).
 *  2. Datei per SFTP in das öffentliche Verzeichnis des Webspace hochladen.
 *  3. Im Browser aufrufen: https://DEINE-ADRESSE/phpcheck.php?t=DEIN-TOKEN
 *  4. Ergebnis ansehen und die Datei danach SOFORT wieder löschen.
 *
 * Die Datei ist absichtlich für alte PHP-Versionen geschrieben (ab 5.6), damit sie
 * auch dann läuft, wenn der Webspace noch eine alte PHP-Version hat. Sie speichert nichts.
 * Zugangsdaten für den Datenbanktest werden nur für diese eine Anfrage benutzt und
 * nie ausgegeben.
 */
$TOKEN = 'HIER-ZUFAELLIGES-TOKEN-EINTRAGEN';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if ($TOKEN === 'HIER-ZUFAELLIGES-TOKEN-EINTRAGEN' || strlen($TOKEN) < 16) {
    http_response_code(403);
    exit('Bitte zuerst in der Datei ein eigenes Token mit mindestens 16 Zeichen eintragen.');
}
$given = isset($_GET['t']) ? (string)$_GET['t'] : '';
if ($given === '' && isset($_POST['t'])) {
    $given = (string)$_POST['t'];
}
if (!hash_equals($TOKEN, $given)) {
    http_response_code(403);
    exit('Zugriff verweigert. Aufruf: phpcheck.php?t=DEIN-TOKEN');
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function to_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '') {
        return 0;
    }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        return $n * 1073741824;
    }
    if ($u === 'm') {
        return $n * 1048576;
    }
    if ($u === 'k') {
        return $n * 1024;
    }
    return $n;
}

/* $ok: true = in Ordnung, false = Problem, null = nur zur Information */
function row($label, $value, $ok = null)
{
    if ($ok === null) {
        $mark = '<span class="info">Info</span>';
    } elseif ($ok) {
        $mark = '<span class="ok">OK</span>';
    } else {
        $mark = '<span class="bad">Problem</span>';
    }
    echo '<tr><td>' . h($label) . '</td><td>' . h($value) . '</td><td>' . $mark . '</td></tr>' . "\n";
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Hosting-Check</title>
<style>
body{font:15px Arial,Helvetica,sans-serif;max-width:820px;margin:20px auto;padding:0 14px;color:#1c1f24}
h1{font-size:22px}h2{font-size:16px;margin:22px 0 6px}
table{width:100%;border-collapse:collapse}
td{border:1px solid #c9ced6;padding:5px 8px;vertical-align:top}
td:first-child{width:38%}td:last-child{width:80px;text-align:center}
.ok{color:#1b7a2f;font-weight:bold}.bad{color:#b00020;font-weight:bold}.info{color:#666}
.warn{background:#fff4d6;border:1px solid #e0b84a;padding:8px 10px;margin:10px 0}
label{display:block;margin:6px 0 2px}input[type=text],input[type=password]{width:100%;max-width:340px;padding:5px;box-sizing:border-box}
button{margin-top:10px;padding:6px 14px}
</style>
</head>
<body>
<h1>Hosting-Check für die Wettkampf-Tools</h1>
<div class="warn"><b>Diese Datei danach sofort vom Webspace löschen.</b> Sie zeigt Angaben über den Server an.</div>

<h2>1. PHP</h2>
<table>
<?php
row('PHP-Version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='));
row('Benötigt', 'mindestens 8.1', null);
row('Betriebsart (SAPI)', php_sapi_name(), null);
row('Server', isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unbekannt', null);
$exts = array('pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'openssl', 'ctype', 'hash');
foreach ($exts as $e) {
    row('Erweiterung ' . $e, extension_loaded($e) ? 'geladen' : 'fehlt', extension_loaded($e));
}
$mem = ini_get('memory_limit');
row('memory_limit', $mem, ($mem === '-1') || to_bytes($mem) >= 64 * 1048576);
row('max_execution_time', ini_get('max_execution_time') . ' s', null);
row('post_max_size', ini_get('post_max_size'), to_bytes(ini_get('post_max_size')) >= 8 * 1048576);
row('upload_max_filesize', ini_get('upload_max_filesize'), null);
if (function_exists('mb_strtoupper')) {
    row('Umlaute (mbstring)', mb_strtoupper('größe äöü', 'UTF-8'), null);
}
?>
</table>

<h2>2. Sitzungen und Verbindung</h2>
<table>
<?php
row('HTTPS', $https ? 'aktiv' : 'nicht erkannt', $https);
$sp = session_save_path();
if ($sp === '') {
    $sp = ini_get('session.save_path');
}
if ($sp === '' || $sp === false) {
    row('Sitzungsordner', 'Standard des Servers', null);
} else {
    row('Sitzungsordner beschreibbar', is_writable($sp) ? 'ja' : 'nein', is_writable($sp));
}
row('session.cookie_httponly', ini_get('session.cookie_httponly') ? 'an' : 'aus (wird von der App selbst gesetzt)', null);
?>
</table>

<h2>3. Ordner</h2>
<table>
<?php
$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
row('Öffentliches Verzeichnis', $docroot, null);
if ($docroot !== '') {
    $parent = dirname($docroot);
    row('Ordner darüber (für die Konfigurationsdatei)', $parent, null);
    row('Ordner darüber lesbar', is_readable($parent) ? 'ja' : 'nein', null);
    row('Ordner darüber beschreibbar (dieser Prozess)', is_writable($parent) ? 'ja' : 'nein', null);
}
if (function_exists('apache_get_modules')) {
    $mods = apache_get_modules();
    row('mod_rewrite', in_array('mod_rewrite', $mods) ? 'vorhanden' : 'fehlt', in_array('mod_rewrite', $mods));
    row('mod_headers', in_array('mod_headers', $mods) ? 'vorhanden' : 'fehlt', null);
} else {
    row('Apache-Module', 'nicht ermittelbar (kein Apache-Modul-PHP)', null);
}
?>
</table>
<p>Die Konfigurationsdatei mit den Zugangsdaten gehört <b>außerhalb</b> des öffentlichen Verzeichnisses.
Prüfe im SFTP-Programm, ob du einen Ordner oberhalb des öffentlichen Verzeichnisses anlegen kannst.</p>

<h2>4. Datenbank (optional)</h2>
<?php
if (!$https) {
    echo '<div class="warn">Ohne HTTPS wird der Datenbanktest nicht angeboten, damit keine Zugangsdaten unverschlüsselt übertragen werden.</div>';
} elseif (isset($_POST['host'])) {
    $host = (string)$_POST['host'];
    $db = isset($_POST['db']) ? (string)$_POST['db'] : '';
    $user = isset($_POST['user']) ? (string)$_POST['user'] : '';
    $pass = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    echo '<table>' . "\n";
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) || !preg_match('/^[A-Za-z0-9._-]+$/', $db)) {
        row('Eingabe', 'Host und Datenbankname dürfen nur Buchstaben, Ziffern, Punkt, Strich und Unterstrich enthalten.', false);
    } elseif (!extension_loaded('pdo_mysql')) {
        row('Verbindung', 'Die Erweiterung pdo_mysql fehlt.', false);
    } else {
        $table = 'bvbb_check_tmp';
        $pdo = null;
        try {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            row('Verbindung', 'hergestellt', true);
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            row('Datenbank-Version', $ver, null);
            $cs = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch(PDO::FETCH_NUM);
            row('Zeichensatz der Datenbank', $cs ? $cs[1] : 'unbekannt', null);
            $inno = $pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetchColumn();
            row('InnoDB', $inno ? $inno : 'nicht gefunden', $inno === 'YES' || $inno === 'DEFAULT');
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                $pdo->exec('CREATE TABLE ' . $table . ' (id INT NOT NULL PRIMARY KEY, txt VARCHAR(60) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                row('Tabelle anlegen (CREATE)', 'ok', true);
                $st = $pdo->prepare('INSERT INTO ' . $table . ' (id, txt) VALUES (1, ?)');
                $st->execute(array('Müßig Köppen Weißkopf'));
                row('Eintragen (INSERT)', 'ok', true);
                $back = $pdo->query('SELECT txt FROM ' . $table . ' WHERE id = 1')->fetchColumn();
                row('Umlaute hin und zurück', $back, $back === 'Müßig Köppen Weißkopf');
            } catch (PDOException $e) {
                row('Schreibrechte', 'Fehler: ' . $e->getMessage(), false);
            }
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                row('Prüftabelle gelöscht (DROP)', 'ok', true);
            } catch (PDOException $e) {
                row('Prüftabelle löschen', 'Fehler: ' . $e->getMessage() . ' Bitte Tabelle ' . $table . ' von Hand löschen.', false);
            }
        } catch (PDOException $e) {
            row('Verbindung', 'Fehler: ' . $e->getMessage(), false);
        }
    }
    echo '</table>' . "\n";
}
if ($https) {
    ?>
<form method="post" action="?t=<?php echo h(rawurlencode($given)); ?>" autocomplete="off">
<input type="hidden" name="t" value="<?php echo h($given); ?>">
<label>Datenbank-Server (Host)</label><input type="text" name="host" placeholder="localhost oder der Name aus dem Kundenmenü">
<label>Datenbankname</label><input type="text" name="db">
<label>Benutzer</label><input type="text" name="user">
<label>Passwort</label><input type="password" name="pass">
<button type="submit">Datenbank testen</button>
</form>
<p>Der Test legt kurz eine Tabelle <code>bvbb_check_tmp</code> an, schreibt einen Satz mit Umlauten, liest ihn zurück und löscht die Tabelle wieder. Das Passwort wird nicht gespeichert und nicht angezeigt.</p>
    <?php
}
?>
<p style="margin-top:30px"><b>Jetzt diese Datei löschen.</b></p>
</body>
</html>

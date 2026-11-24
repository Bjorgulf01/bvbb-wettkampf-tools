<?php
declare(strict_types=1);

/*
 * Baut das Paket für den Webspace nach dist/bvbb-wettkampf-<Version>/ und dist/bvbb-wettkampf-<Version>.zip.
 * Aufruf (im Container): docker compose -f tools/dev-env/docker-compose.dev.yml run --rm php php tools/package.php
 * Nur aus main ohne offene Änderungen, damit das Paket immer zu einem Commit passt.
 */

use App\Version;
use Tools\Package\Packager;

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$repo = str_replace('\\', '/', dirname(__DIR__));
require $repo . '/src/autoload.php';
require_once $repo . '/tools/package/Packager.php';

/** Führt git im Repository aus. Gibt die Ausgabe zurück oder null bei einem Fehler. */
$git = static function (string $args) use ($repo): ?string {
    $out = [];
    $code = 0;
    exec('git -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1', $out, $code);
    return $code === 0 ? implode("\n", $out) : null;
};

$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

$branch = $git('rev-parse --abbrev-ref HEAD');
$dirty = $git('status --porcelain');
if ($branch === null || $dirty === null) {
    $fail('git ist nicht verfügbar oder dies ist kein Git-Repository.');
}
if (trim($branch) !== 'main' || trim($dirty) !== '') {
    $fail("Paket nur aus main ohne offene Änderungen. Aktueller Zweig: " . trim($branch)
        . (trim($dirty) !== '' ? ", offene Änderungen:\n" . $dirty : ''));
}
// Von Git ignorierte Dateien (.env, echte Mitgliederlisten, …) zeigt git status nicht an.
// Liegen sie in einer Quelle, kämen sie unbemerkt ins Paket, im schlimmsten Fall öffentlich nach webseiten/.
$sources = implode(' ', array_map('escapeshellarg', array_keys(Packager::MAP)));
$ignored = $git('ls-files --others --ignored --exclude-standard -- ' . $sources);
if ($ignored === null) {
    $fail('git ls-files ist fehlgeschlagen.');
}
if (trim($ignored) !== '') {
    $fail("Abbruch: Von Git ignorierte Dateien liegen in den Quellordnern und kämen ins Paket:\n"
        . $ignored . "\nBitte entfernen oder verschieben, dann neu starten.");
}
$commit = trim((string) $git('rev-parse --short HEAD'));

$name = 'bvbb-wettkampf-' . Version::NUMBER;
$dist = $repo . '/dist';
$target = $dist . '/' . $name;
$zipPath = $target . '.zip';

/** Löscht $path rekursiv, aber nur innerhalb von dist/. */
$remove = static function (string $path) use ($dist, &$remove, $fail): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    $real = realpath($path);
    $realDist = realpath($dist);
    if ($real === false || $realDist === false || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $realDist) . '/')) {
        $fail('Lösche nur innerhalb von dist/: ' . $path);
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};

if (!is_dir($dist)) {
    mkdir($dist, 0777, true);
}
$remove($target);
$remove($zipPath);

try {
    $result = (new Packager($repo))->build(
        $target,
        Version::NUMBER,
        $commit,
        new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'))
    );
} catch (RuntimeException $e) {
    $fail('Abbruch: ' . $e->getMessage());
}

echo 'Ordner:   dist/' . $name . "/\n";
echo $result['zip'] !== null ? 'ZIP:      dist/' . $name . ".zip\n" : "ZipArchive fehlt, nur Ordner gebaut.\n";
echo 'Dateien:  ' . count($result['files']) . "\n";
echo 'Version ' . Version::NUMBER . ', Commit ' . $commit . ". Anleitung: HOCHLADEN.txt im Paket.\n";

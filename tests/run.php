<?php
declare(strict_types=1);

/*
 * Test-Runner.   Aufruf: php tests/run.php [Filter]
 * Jede Datei tests/*Test.php gibt ein Array aus Name => Funktion zurück.
 * Der Filter wählt Dateien, deren Name ihn enthält (ohne Beachtung der Großschreibung).
 * Exit-Code 1 bei Fehlern. Übersprungene Tests (skip()) werden gemeldet und zählen nie als Erfolg.
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/support/assert.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($files as $file) {
    $suite = basename($file, 'Test.php');
    if ($filter !== '' && stripos(basename($file), $filter) === false) {
        continue;
    }
    $tests = require $file;
    if (!is_array($tests)) {
        echo "FAIL  $suite: Testdatei gibt kein Array zurück\n";
        $failed++;
        continue;
    }
    foreach ($tests as $name => $fn) {
        $label = $suite . ': ' . $name;
        try {
            $fn();
            echo "PASS  $label\n";
            $passed++;
        } catch (SkipTest $e) {
            echo "SKIPPED  $label (" . $e->getMessage() . ")\n";
            $skipped++;
        } catch (Throwable $e) {
            echo "FAIL  $label\n      " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$total = $passed + $failed + $skipped;
if ($total === 0) {
    echo "0 tests\n";
    exit(0);
}
echo "\n$passed passed, $failed failed, $skipped skipped\n";
if ($skipped > 0) {
    echo "Achtung: Übersprungene Tests zählen nicht als Erfolg.\n";
}
exit($failed > 0 ? 1 : 0);

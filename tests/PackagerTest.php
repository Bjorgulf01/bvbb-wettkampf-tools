<?php
declare(strict_types=1);

use Tools\Package\Packager;

require_once __DIR__ . '/../tools/package/Packager.php';

/** Löscht einen Ordner samt Inhalt (nur für temporäre Testordner). */
$rmTree = static function (string $dir) use (&$rmTree): void {
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_file($dir) || is_link($dir)) {
        unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $rmTree($dir . '/' . $name);
        }
    }
    rmdir($dir);
};

/** Legt ein Mini-Repository in einem neuen temporären Ordner an und gibt dessen Pfad zurück. */
$miniRepo = static function (): string {
    $tmp = sys_get_temp_dir() . '/bvbb-pkg-' . bin2hex(random_bytes(6));
    $files = [
        'public/index.php' => "<?php // index\n",
        'public/.htaccess' => "RewriteEngine On\n",
        'public/.user.ini' => "display_errors = Off\n",
        'public/vendor/xlsx.full.min.js' => "/* xlsx */\n",
        'src/Version.php' => "<?php // Version\n",
        'migrations/001_core.sql' => "-- 001\n",
        'migrations/002_seed_roles.sql' => "-- 002\n",
        'bin/migrate.php' => "<?php // migrate\n",
        '.htaccess' => "Require all denied\n",
        'config.example.php' => "<?php return [];\n",
        'config.php' => "<?php return ['geheim' => true];\n",
        'sessions/sess_x' => "sitzung\n",
        'tests/XTest.php' => "<?php return [];\n",
    ];
    foreach ($files as $rel => $content) {
        $path = $tmp . '/repo/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
    return $tmp;
};

$now = new DateTimeImmutable('2026-11-24 12:00', new DateTimeZone('Europe/Berlin'));

/** Baut das Mini-Repository nach <tmp>/out/bvbb-wettkampf-9.9.9. */
$buildMini = static function (string $tmp) use ($now): array {
    return (new Packager($tmp . '/repo'))->build($tmp . '/out/bvbb-wettkampf-9.9.9', '9.9.9', 'abc1234', $now);
};

/** Führt $fn mit einem frischen Mini-Repository aus und räumt danach auf. */
$withMini = static function (callable $fn) use ($miniRepo, $rmTree): void {
    $tmp = $miniRepo();
    try {
        $fn($tmp);
    } finally {
        $rmTree($tmp);
    }
};

return [
    'test_maps_sources_to_webspace_layout' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            $result = $buildMini($tmp);
            $expected = [
                'webseiten/index.php' => 'public/index.php',
                'webseiten/.htaccess' => 'public/.htaccess',
                'webseiten/.user.ini' => 'public/.user.ini',
                'webseiten/vendor/xlsx.full.min.js' => 'public/vendor/xlsx.full.min.js',
                'bvbb-app/src/Version.php' => 'src/Version.php',
                'bvbb-app/migrations/001_core.sql' => 'migrations/001_core.sql',
                'bvbb-app/migrations/002_seed_roles.sql' => 'migrations/002_seed_roles.sql',
                'bvbb-app/bin/migrate.php' => 'bin/migrate.php',
                'bvbb-app/.htaccess' => '.htaccess',
                'bvbb-app/config.example.php' => 'config.example.php',
            ];
            foreach ($expected as $pkg => $src) {
                assertTrue(in_array($pkg, $result['files'], true), "fehlt in files: $pkg");
                $target = $result['dir'] . '/' . $pkg;
                assertTrue(is_file($target), "fehlt im Ordner: $pkg");
                assertSame(file_get_contents($tmp . '/repo/' . $src), file_get_contents($target), "Inhalt $pkg");
            }
            assertTrue(in_array('HOCHLADEN.txt', $result['files'], true), 'HOCHLADEN.txt fehlt in files');
            assertTrue(is_file($result['dir'] . '/HOCHLADEN.txt'), 'HOCHLADEN.txt fehlt im Ordner');
            assertSame(count($expected) + 1, count($result['files']), 'Anzahl der Dateien');
            $sorted = $result['files'];
            sort($sorted, SORT_STRING);
            assertSame($sorted, $result['files'], 'files ist sortiert');
            assertSame($tmp . '/out/bvbb-wettkampf-9.9.9', $result['dir']);
        });
    },

    'test_never_contains_config_sessions_tests' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            $result = $buildMini($tmp);
            foreach ($result['files'] as $f) {
                assertTrue(!str_ends_with($f, 'config.php') || str_ends_with($f, 'config.example.php'), "config.php im Paket: $f");
                assertTrue(!str_contains($f, 'sessions/'), "sessions/ im Paket: $f");
                assertTrue(!str_contains($f, 'tests/'), "tests/ im Paket: $f");
                assertTrue(!str_contains($f, '.htpasswd'), ".htpasswd im Paket: $f");
            }
            assertTrue(!file_exists($result['dir'] . '/bvbb-app/config.php'), 'config.php liegt im Ordner');
            assertTrue(!file_exists($result['dir'] . '/bvbb-app/sessions'), 'sessions/ liegt im Ordner');
        });
    },

    'test_aborts_on_config_in_source' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            file_put_contents($tmp . '/repo/public/config.php', "<?php\n");
            try {
                $buildMini($tmp);
                throw new AssertionFailure('erwartet RuntimeException für public/config.php');
            } catch (RuntimeException $e) {
                assertTrue(str_contains($e->getMessage(), 'public/config.php'), 'Meldung nennt den Pfad: ' . $e->getMessage());
            }
            assertTrue(!file_exists($tmp . '/out/bvbb-wettkampf-9.9.9'), 'Zielordner darf nicht entstehen');
        });
        $withMini(function (string $tmp) use ($buildMini): void {
            mkdir($tmp . '/repo/src/sessions');
            file_put_contents($tmp . '/repo/src/sessions/sess_y', "x\n");
            assertThrows(fn() => $buildMini($tmp), RuntimeException::class);
            assertTrue(!file_exists($tmp . '/out/bvbb-wettkampf-9.9.9'), 'Zielordner darf nicht entstehen');
        });
        $withMini(function (string $tmp) use ($buildMini): void {
            file_put_contents($tmp . '/repo/src/.htpasswd', "user:hash\n");
            assertThrows(fn() => $buildMini($tmp), RuntimeException::class);
        });
        $withMini(function (string $tmp) use ($buildMini): void {
            // Exakter Vergleich: src/Config.php ist die Klasse App\Config, keine Zugangsdatei.
            file_put_contents($tmp . '/repo/src/Config.php', "<?php // Klasse\n");
            $result = $buildMini($tmp);
            assertTrue(in_array('bvbb-app/src/Config.php', $result['files'], true), 'src/Config.php gehört ins Paket');
        });
    },

    'test_refuses_existing_target' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            mkdir($tmp . '/out/bvbb-wettkampf-9.9.9', 0777, true);
            file_put_contents($tmp . '/out/bvbb-wettkampf-9.9.9/alt.php', "alt\n");
            assertThrows(fn() => $buildMini($tmp), RuntimeException::class);
            assertSame(['.', '..', 'alt.php'], scandir($tmp . '/out/bvbb-wettkampf-9.9.9'), 'vorhandener Ordner bleibt unberührt');
        });
    },

    'test_missing_source_aborts' => function () use ($withMini, $buildMini, $rmTree): void {
        $withMini(function (string $tmp) use ($buildMini, $rmTree): void {
            $rmTree($tmp . '/repo/bin');
            try {
                $buildMini($tmp);
                throw new AssertionFailure('erwartet RuntimeException für fehlendes bin/');
            } catch (RuntimeException $e) {
                assertTrue(str_contains($e->getMessage(), 'bin'), 'Meldung nennt bin: ' . $e->getMessage());
            }
            assertTrue(!file_exists($tmp . '/out/bvbb-wettkampf-9.9.9'), 'Zielordner darf nicht entstehen');
        });
    },

    'test_aborts_on_symlink' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            if (!@symlink($tmp . '/repo/config.php', $tmp . '/repo/src/link.php')) {
                skip('symbolische Links hier nicht möglich');
            }
            assertThrows(fn() => $buildMini($tmp), RuntimeException::class);
            assertTrue(!file_exists($tmp . '/out/bvbb-wettkampf-9.9.9'), 'Zielordner darf nicht entstehen');
        });
    },

    'test_hochladen_txt' => function () use ($withMini, $buildMini): void {
        $withMini(function (string $tmp) use ($buildMini): void {
            $result = $buildMini($tmp);
            $text = file_get_contents($result['dir'] . '/HOCHLADEN.txt');
            foreach (['9.9.9', 'abc1234', '24.11.2026', '001_core.sql', '002_seed_roles.sql'] as $needle) {
                assertTrue(str_contains($text, $needle), "HOCHLADEN.txt enthält nicht: $needle");
            }
            $lines = explode("\n", $text);
            foreach ($result['files'] as $f) {
                assertTrue(in_array('  ' . $f, $lines, true), "Dateiliste enthält nicht: $f");
            }
            foreach (['hochladen', 'schema_migrations', 'phpMyAdmin', '/api/health'] as $step) {
                assertTrue(stripos($text, $step) !== false, "Schritt fehlt: $step");
            }
            assertTrue(!str_contains($text, '\\'), 'HOCHLADEN.txt enthält einen Backslash');
            // Fehlt der Sitzungsordner nach dem Hochladen, meldet /api/health {"ok":false} (26.11.2026).
            assertTrue(str_contains($text, 'bvbb-app/sessions/ nicht löschen'), 'Hinweis zum Sitzungsordner fehlt');
            assertTrue(str_contains($text, '{"ok":false}'), 'Hinweis auf {"ok":false} fehlt');
        });
    },

    'test_zip_matches_folder' => function () use ($withMini, $buildMini): void {
        if (!class_exists(ZipArchive::class)) {
            skip('ZipArchive fehlt');
        }
        $withMini(function (string $tmp) use ($buildMini): void {
            $result = $buildMini($tmp);
            assertSame($tmp . '/out/bvbb-wettkampf-9.9.9.zip', $result['zip']);
            $zip = new ZipArchive();
            assertSame(true, $zip->open($result['zip']));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $expected = array_map(fn(string $f): string => 'bvbb-wettkampf-9.9.9/' . $f, $result['files']);
            sort($names, SORT_STRING);
            assertSame($expected, $names, 'Einträge der ZIP-Datei');
            foreach ($names as $n) {
                assertTrue(!str_contains($n, '\\'), "Backslash in ZIP-Eintrag: $n");
            }
            assertSame(
                file_get_contents($result['dir'] . '/webseiten/.htaccess'),
                $zip->getFromName('bvbb-wettkampf-9.9.9/webseiten/.htaccess'),
                'Inhalt in der ZIP-Datei'
            );
            $zip->close();
        });
    },

    'test_cli_refuses_ignored_files_in_sources' => function () use ($rmTree): void {
        // Eine von Git ignorierte Datei (.env, echte Mitgliederliste) taucht in git status nicht auf
        // und darf trotzdem nie ins Paket. Neues Repository aus dem Arbeitsstand, Zweig main, ein Commit.
        exec('git --version 2>&1', $o, $code);
        if ($code !== 0) {
            skip('git fehlt');
        }
        $tmp = sys_get_temp_dir() . '/bvbb-pkg-cli-' . bin2hex(random_bytes(6));
        $dir = escapeshellarg($tmp);
        $parts = ['public', 'src', 'migrations', 'bin', '.htaccess', 'config.example.php', '.gitignore', 'tools/package.php', 'tools/package'];
        try {
            mkdir($tmp . '/tools', 0777, true);
            foreach ($parts as $p) {
                exec('cp -R ' . escapeshellarg(dirname(__DIR__) . '/' . $p) . ' ' . escapeshellarg($tmp . '/' . $p) . ' 2>&1', $o, $code);
                assertSame(0, $code, "Kopie von $p: " . implode("\n", $o));
            }
            exec("git -C $dir init -q -b main 2>&1 && git -C $dir add -A 2>&1"
                . " && git -C $dir -c user.name=Test -c user.email=test@example.org commit -q -m test 2>&1", $o, $code);
            assertSame(0, $code, 'Test-Repository: ' . implode("\n", $o));
            file_put_contents($tmp . '/public/.env', "DB_PASS=erfunden\n");
            file_put_contents($tmp . '/migrations/Mitglieder_fiktiv.csv', "Name;Vorname\n");
            $out = [];
            exec('php ' . escapeshellarg($tmp . '/tools/package.php') . ' 2>&1', $out, $code);
            $text = implode("\n", $out);
            assertSame(1, $code, 'Exit-Code: ' . $text);
            assertTrue(str_contains($text, 'public/.env'), 'Meldung nennt public/.env: ' . $text);
            assertTrue(str_contains($text, 'migrations/Mitglieder_fiktiv.csv'), 'Meldung nennt die Liste: ' . $text);
            assertTrue(!file_exists($tmp . '/dist/bvbb-wettkampf-' . App\Version::NUMBER), 'kein Paket gebaut');

            unlink($tmp . '/public/.env');
            unlink($tmp . '/migrations/Mitglieder_fiktiv.csv');
            $out = [];
            exec('php ' . escapeshellarg($tmp . '/tools/package.php') . ' 2>&1', $out, $code);
            assertSame(0, $code, 'sauberer Lauf: ' . implode("\n", $out));
            assertTrue(is_file($tmp . '/dist/bvbb-wettkampf-' . App\Version::NUMBER . '/webseiten/index.php'), 'Paket gebaut');
        } finally {
            $rmTree($tmp);
        }
    },

    'test_real_repository' => function () use ($rmTree, $now): void {
        $repo = dirname(__DIR__);
        $tmp = sys_get_temp_dir() . '/bvbb-pkg-real-' . bin2hex(random_bytes(6));
        try {
            $result = (new Packager($repo))->build($tmp . '/bvbb-wettkampf-0.0.0', '0.0.0', 'test', $now);
            foreach (['webseiten/index.php', 'webseiten/.htaccess', 'webseiten/.user.ini', 'bvbb-app/.htaccess', 'bvbb-app/src/Version.php'] as $f) {
                assertTrue(in_array($f, $result['files'], true), "fehlt: $f");
            }
            $migrations = glob($repo . '/migrations/*.sql') ?: [];
            assertTrue(count($migrations) >= 2, 'Migrationen gefunden');
            foreach ($migrations as $m) {
                $f = 'bvbb-app/migrations/' . basename($m);
                assertTrue(in_array($f, $result['files'], true), "fehlt: $f");
            }
            foreach ($result['files'] as $f) {
                assertTrue(!str_ends_with($f, 'config.php') || str_ends_with($f, 'config.example.php'), "config.php im Paket: $f");
                assertTrue(!str_contains($f, 'sessions/') && !str_contains($f, 'tests/'), "verbotener Pfad: $f");
            }
        } finally {
            $rmTree($tmp);
        }
    },
];

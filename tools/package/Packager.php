<?php
declare(strict_types=1);

namespace Tools\Package;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Baut aus dem Repository ein Paket im Aufbau des Webspace (webseiten/ und bvbb-app/).
 * Positivliste: Nur die Quellen aus MAP kommen ins Paket. Nur Entwicklungswerkzeug, wird nicht ausgeliefert.
 */
final class Packager
{
    /** Quelle (relativ zum Repository) => Ziel (relativ zum Paket). */
    public const MAP = [
        'public' => 'webseiten',
        'src' => 'bvbb-app/src',
        'migrations' => 'bvbb-app/migrations',
        'bin' => 'bvbb-app/bin',
        '.htaccess' => 'bvbb-app/.htaccess',
        'config.example.php' => 'bvbb-app/config.example.php',
    ];

    /**
     * Ein Eintrag mit genau diesem Namen irgendwo unter einer Quelle bricht den Bau ab.
     * Exakter Vergleich, weil src/Config.php die Klasse App\Config ist.
     */
    public const FORBIDDEN_NAMES = ['config.php', '.htpasswd', 'sessions'];

    private const README_NAME = 'HOCHLADEN.txt';

    public function __construct(private string $repoDir)
    {
    }

    /**
     * @return array{dir: string, zip: ?string, files: list<string>}
     */
    public function build(string $targetDir, string $version, string $commit, DateTimeImmutable $now): array
    {
        if (file_exists($targetDir) || is_link($targetDir)) {
            throw new RuntimeException('Zielordner existiert schon: ' . self::slashes($targetDir));
        }

        // Erst alles prüfen, dann schreiben: Bei einem Fehler entsteht kein halbes Paket.
        $entries = $this->collect();

        foreach ($entries as $pkgPath => $source) {
            $dest = $targetDir . '/' . $pkgPath;
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException('Ordner lässt sich nicht anlegen: ' . self::slashes($dir));
            }
            if (!copy($source, $dest)) {
                throw new RuntimeException('Datei lässt sich nicht kopieren: ' . $pkgPath);
            }
        }

        $files = array_keys($entries);
        $files[] = self::README_NAME;
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $f) {
            if (preg_match('#^bvbb-app/migrations/([^/]+\.sql)$#', $f, $m) === 1) {
                $migrations[] = $m[1];
            }
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        file_put_contents($targetDir . '/' . self::README_NAME, self::readme($version, $commit, $now, $migrations, $files));

        $zip = $this->zip($targetDir, $files);

        return ['dir' => $targetDir, 'zip' => $zip, 'files' => $files];
    }

    /**
     * Paketpfad (mit /) => Quelldatei. Wirft bei fehlender Quelle, verbotenem Namen oder symbolischem Link.
     *
     * @return array<string, string>
     */
    private function collect(): array
    {
        $entries = [];
        foreach (self::MAP as $src => $dst) {
            $abs = $this->repoDir . '/' . $src;
            if (is_link($abs)) {
                throw new RuntimeException('Symbolischer Link wird nicht gepackt: ' . $src);
            }
            if (is_file($abs)) {
                $entries[$dst] = $abs;
                continue;
            }
            if (!is_dir($abs)) {
                throw new RuntimeException('Quelle fehlt: ' . $src);
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            /** @var SplFileInfo $item */
            foreach ($it as $item) {
                $rel = self::slashes(substr($item->getPathname(), strlen($abs) + 1));
                $repoPath = $src . '/' . $rel;
                if ($item->isLink()) {
                    throw new RuntimeException('Symbolischer Link wird nicht gepackt: ' . $repoPath);
                }
                if (in_array($item->getFilename(), self::FORBIDDEN_NAMES, true)) {
                    throw new RuntimeException('Verbotene Datei in einer Quelle, Paket abgebrochen: ' . $repoPath);
                }
                if ($item->isFile()) {
                    $entries[$dst . '/' . $rel] = $item->getPathname();
                }
            }
        }
        return $entries;
    }

    /** @param list<string> $files */
    private function zip(string $targetDir, array $files): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $name = basename($targetDir);
        $path = dirname($targetDir) . '/' . $name . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei lässt sich nicht anlegen: ' . self::slashes($path));
        }
        foreach ($files as $f) {
            $zip->addFile($targetDir . '/' . $f, $name . '/' . $f);
        }
        if (!$zip->close()) {
            throw new RuntimeException('ZIP-Datei lässt sich nicht schreiben: ' . self::slashes($path));
        }
        return $path;
    }

    /**
     * @param list<string> $migrations
     * @param list<string> $files
     */
    private static function readme(string $version, string $commit, DateTimeImmutable $now, array $migrations, array $files): string
    {
        $lines = [
            'BVBB Wettkampf-Tools – Paket für den Webspace',
            '',
            'Version: ' . $version,
            'Commit:  ' . $commit,
            'Erstellt: ' . $now->format('d.m.Y H:i'),
            '',
            'So wird hochgeladen:',
            '1. Migrationen zuerst: In phpMyAdmin die Tabelle schema_migrations mit der Liste unten vergleichen.',
            '   Fehlende Dateien aus bvbb-app/migrations/ in der Reihenfolge der Nummern importieren.',
            '2. Ordner hochladen: webseiten/ und bvbb-app/ auf die gleichnamigen Ordner im Webspace legen.',
            '   Versteckte Dateien einblenden (.htaccess, .user.ini). config.php und sessions/ sind nicht im Paket',
            '   und bleiben auf dem Server unverändert.',
            '   Wichtig: bvbb-app/sessions/ nicht löschen. Nur bvbb-app/src/ vollständig ersetzen, nicht den ganzen',
            '   Ordner bvbb-app/. Fehlt sessions/, den leeren Ordner neu anlegen (Rechte 755, notfalls 775).',
            '3. https://…/api/health aufrufen. Erwartet: {"ok":true,"version":"' . $version . '"}',
            '   Kommt {"ok":false}: zuerst prüfen, ob bvbb-app/sessions/ da und beschreibbar ist, dann die',
            '   Datenbankdaten in config.php.',
            '',
            'Hochladen löscht keine alten Dateien. Die Dateiliste unten hilft, Dateien auf dem Server zu finden,',
            'die nicht mehr gebraucht werden.',
            '',
            'Migrationen (' . count($migrations) . '):',
        ];
        foreach ($migrations as $m) {
            $lines[] = '  ' . $m;
        }
        $lines[] = '';
        $lines[] = 'Dateien (' . count($files) . '):';
        foreach ($files as $f) {
            $lines[] = '  ' . $f;
        }
        return implode("\n", $lines) . "\n";
    }

    private static function slashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

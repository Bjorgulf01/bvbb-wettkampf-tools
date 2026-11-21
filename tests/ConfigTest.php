<?php
declare(strict_types=1);

use App\Config;

return [
    'get liefert verschachtelten Wert per Punktschreibweise' => function (): void {
        $c = new Config(['db' => ['dsn' => 'mysql:host=x;dbname=y']]);
        assertSame('mysql:host=x;dbname=y', $c->get('db.dsn'));
    },
    'fehlender Schlüssel liefert Default' => function (): void {
        $c = new Config(['db' => ['dsn' => 'a']]);
        assertSame(null, $c->get('db.user'));
        assertSame('fallback', $c->get('db.user', 'fallback'));
        assertSame(7, $c->get('nope.nothing.here', 7));
    },
    'fromFile lädt eine Datei, die ein Array zurückgibt' => function (): void {
        $path = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($path, "<?php return ['env'=>'development'];");
        try {
            $c = Config::fromFile($path);
            assertSame('development', $c->get('env'));
        } finally {
            unlink($path);
        }
    },
    'fromFile meldet fehlende oder ungültige Datei' => function (): void {
        assertThrows(fn() => Config::fromFile('/nicht/vorhanden.php'), InvalidArgumentException::class);
        $path = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($path, '<?php return 5;');
        try {
            assertThrows(fn() => Config::fromFile($path), InvalidArgumentException::class);
        } finally {
            unlink($path);
        }
    },
];

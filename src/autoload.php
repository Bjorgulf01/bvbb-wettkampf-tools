<?php
declare(strict_types=1);

// Lädt Klassen des Namensraums App\ aus src/.
// Dateinamen entsprechen exakt den Klassennamen (Groß-/Kleinschreibung zählt auf dem Webserver).
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/** Konfigurationswerte mit Punktschreibweise, zum Beispiel get('db.dsn'). */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /** Lädt eine PHP-Datei, die ein Array zurückgibt. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Konfigurationsdatei nicht gefunden');
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new InvalidArgumentException('Konfigurationsdatei muss ein Array zurückgeben');
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}

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

    /** Erlaubte Umgebungen. staging ist der Test-Webspace (Testanmeldung erlaubt, Cookies mit Secure). */
    public const ENVIRONMENTS = ['development', 'staging', 'production'];

    /**
     * Bricht den Start ab, wenn die Konfiguration unsicher ist: unbekannte oder fehlende Umgebung,
     * oder Testanmeldung im Echtbetrieb. Im Echtbetrieb ist jeder Wert von test_auth außer false
     * oder fehlend unsicher, auch 1 oder "ja".
     * @throws UnsafeConfig
     */
    public function assertSafe(): void
    {
        $env = $this->get('env');
        if (!in_array($env, self::ENVIRONMENTS, true)) {
            throw new UnsafeConfig('Unbekannte Umgebung in der Konfiguration (env)');
        }
        $testAuth = $this->get('test_auth');
        if ($env === 'production' && $testAuth !== null && $testAuth !== false) {
            throw new UnsafeConfig('Testanmeldung ist im Echtbetrieb nicht erlaubt');
        }
        $maxAge = $this->get('session_max_age');
        if ($maxAge !== null && (!is_int($maxAge) || $maxAge <= 0)) {
            throw new UnsafeConfig('session_max_age muss eine positive ganze Zahl (Sekunden) sein');
        }
    }

    /**
     * Testanmeldung nur bei ausdrücklich true und nur in development oder staging.
     * Im Echtbetrieb ist sie damit auch dann aus, wenn assertSafe nicht aufgerufen wurde.
     */
    public function testAuthEnabled(): bool
    {
        return $this->get('test_auth') === true
            && in_array($this->get('env'), ['development', 'staging'], true);
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

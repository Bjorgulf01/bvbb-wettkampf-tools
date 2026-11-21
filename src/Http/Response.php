<?php
declare(strict_types=1);

namespace App\Http;

use JsonException;

/** JSON-Antwort. Beträge, Namen und Umlaute werden unverändert (UTF-8) ausgegeben. */
final class Response
{
    private const DEFAULT_HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'no-store',
    ];

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers zusätzliche Header (zum Beispiel Allow)
     */
    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        // Zusätzliche Header ersetzen Standard-Header ohne Beachtung der Großschreibung.
        $merged = self::DEFAULT_HEADERS;
        foreach ($headers as $name => $value) {
            foreach (array_keys($merged) as $existing) {
                if (strcasecmp($existing, (string) $name) === 0) {
                    unset($merged[$existing]);
                    $name = $existing;
                }
            }
            $merged[(string) $name] = (string) $value;
        }
        return new self($status, $body, $merged);
    }

    /**
     * Ein leerer Body wird als Objekt {} ausgegeben, nicht als Liste [].
     * @throws JsonException bei Daten, die sich nicht kodieren lassen (zum Beispiel ungültiges UTF-8)
     */
    public function encode(): string
    {
        if ($this->body === []) {
            return '{}';
        }
        return json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        try {
            $payload = $this->encode();
            $status = $this->status;
            $headers = $this->headers;
        } catch (JsonException) {
            $payload = '{"error":"server_error"}';
            $status = 500;
            $headers = self::DEFAULT_HEADERS;
        }
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $payload;
    }
}

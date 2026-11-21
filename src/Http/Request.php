<?php
declare(strict_types=1);

namespace App\Http;

use JsonException;

/** Eine eingehende Anfrage. Der Pfad enthält keinen Abfrageteil, die Methode ist groß geschrieben. */
final class Request
{
    /** Größter erlaubter Body einer API-Anfrage (Kaderimport als JSON eingeschlossen). */
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    public const BODY_INVALID_JSON = 'invalid_json';
    public const BODY_UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';
    public const BODY_TOO_LARGE = 'too_large';

    public readonly string $method;
    public readonly string $path;

    /** @var array<string, string> Header-Namen in Kleinbuchstaben */
    private array $headers = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<mixed>|null $json Objekt oder Liste aus dem Body, null ohne Body
     * @param string|null $bodyError einer der BODY_*-Werte, wenn der Body nicht angenommen wird
     */
    public function __construct(
        string $method,
        string $path,
        public readonly array $query = [],
        array $headers = [],
        public readonly ?array $json = null,
        public readonly ?string $bodyError = null,
    ) {
        $this->method = strtoupper($method);
        $this->path = explode('?', $path, 2)[0];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    /**
     * Baut eine Anfrage aus Rohdaten (URI mit Abfrageteil, Body als Text).
     * Ein Body wird nur mit Content-Type application/json angenommen. Das verhindert, dass
     * fremde Seiten mit einfachen Formular-POSTs (text/plain) JSON einschleusen.
     */
    public static function fromParts(string $method, string $uri, array $query, array $headers, string $body): self
    {
        $request = new self($method, $uri, $query, $headers);
        if (trim($body) === '') {
            return $request;
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new self($method, $uri, $query, $headers, null, self::BODY_TOO_LARGE);
        }
        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'), 2)[0]));
        if ($type !== 'application/json') {
            return new self($method, $uri, $query, $headers, null, self::BODY_UNSUPPORTED_MEDIA_TYPE);
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            $decoded = null;
        }
        if (!is_array($decoded)) {
            return new self($method, $uri, $query, $headers, null, self::BODY_INVALID_JSON);
        }
        return new self($method, $uri, $query, $headers, $decoded);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $key, 5))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // Zu große Bodys gar nicht erst einlesen (memory_limit 128M).
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            return new self($method, $uri, $_GET, $headers, null, self::BODY_TOO_LARGE);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        return self::fromParts($method, $uri, $_GET, $headers, $body);
    }

    /** Header ohne Beachtung der Großschreibung, null wenn nicht vorhanden. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

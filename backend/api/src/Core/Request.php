<?php

declare(strict_types=1);

namespace Kaneas\Core;

final class Request
{
    private const MAX_BODY_BYTES = 1_048_576;

    /** The authenticated user (set by the router for protected routes). */
    public ?array $user = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $body,
        private readonly array $query,
        private readonly array $headers,
        private readonly array $cookies,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        // Some Apache/CGI setups strip the Authorization header; .htaccess re-exposes it.
        $headers['authorization'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($headers['authorization'] === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $headers['authorization'] = $value;
                }
            }
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        return new self(
            $method,
            self::resolvePath(),
            self::parseBody($method, $headers['content-type'] ?? ''),
            $_GET,
            array_filter($headers, static fn ($v) => $v !== null),
            $_COOKIE,
            $_SERVER,
        );
    }

    /** Path relative to the api folder, e.g. "boards/5". Works in any sub folder. */
    private static function resolvePath(): string
    {
        $uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $path = trim($uri, '/');
        if (str_starts_with($path, 'index.php')) {
            $path = trim(substr($path, strlen('index.php')), '/');
        }
        return $path;
    }

    private static function parseBody(string $method, string $contentType): array
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [];
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'payload_too_large');
        }
        if (!str_contains(strtolower($contentType), 'application/json')) {
            throw new HttpException(415, 'unsupported_media_type');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json');
        }
        return is_array($data) ? $data : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    public function all(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    public function ip(): string
    {
        // Deliberately ignores X-Forwarded-For: it is client controlled.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off')
            || (int) ($this->server['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** Public URL path of the api folder, e.g. "/kanban/api". */
    public function apiBasePath(): string
    {
        return rtrim(str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/'))), '/');
    }

    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }
}

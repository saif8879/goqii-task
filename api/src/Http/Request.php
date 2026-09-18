<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;

class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array<string, mixed> */
    private $query;

    /** @var string */
    private $rawBody;

    /** @var array<string, string> Header names lower-cased. */
    private $headers;

    /** @var array<string, string> */
    private $cookies;

    /** @var array<string, mixed>|null */
    private $parsedBody;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        string $method,
        string $path,
        array $query,
        string $rawBody,
        array $headers = [],
        array $cookies = []
    ) {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->rawBody = $rawBody;
        $this->headers = $headers;
        $this->cookies = $cookies;
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            rtrim($path === null || $path === '' ? '/' : $path, '/') ?: '/',
            $_GET,
            (string) file_get_contents('php://input'),
            self::captureHeaders(),
            $_COOKIE
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null || preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The decoded JSON body. An empty body is treated as an empty object so
     * callers can rely on getting an array back.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        if (trim($this->rawBody) === '') {
            return $this->parsedBody = [];
        }

        $decoded = json_decode($this->rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new HttpException(400, 'Request body must be valid JSON.');
        }

        return $this->parsedBody = $decoded;
    }

    /**
     * @return array<string, string>
     */
    private static function captureHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }

        // Apache moves Authorization into REDIRECT_HTTP_AUTHORIZATION when the
        // request has been rewritten, and some SAPIs drop it entirely, so fall
        // back to the server array rather than losing the bearer token.
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!isset($headers['authorization']) && isset($_SERVER[$key])) {
                $headers['authorization'] = (string) $_SERVER[$key];
            }
        }

        if (!isset($headers['user-agent']) && isset($_SERVER['HTTP_USER_AGENT'])) {
            $headers['user-agent'] = (string) $_SERVER['HTTP_USER_AGENT'];
        }

        return $headers;
    }
}

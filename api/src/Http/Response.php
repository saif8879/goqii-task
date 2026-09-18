<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
    /**
     * @param mixed                 $payload
     * @param array<string, string> $headers
     */
    public static function json($payload, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }
}

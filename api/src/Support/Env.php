<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal reader for a KEY=VALUE environment file.
 *
 * Under Docker the container supplies its own environment and this does
 * nothing. It exists so that running the API directly, or running a CLI script,
 * does not require exporting a dozen variables by hand.
 */
class Env
{
    /** @var bool */
    private static $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded || !is_readable($path)) {
            return;
        }

        self::$loaded = true;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = self::unquote(trim($value));

            // A real environment variable always wins. A file left behind in an
            // image must never override what the platform injected.
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
        }
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];

            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}

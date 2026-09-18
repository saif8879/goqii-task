<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Typed access to environment configuration.
 *
 * Nothing that affects security has a permissive default. A missing JWT secret
 * or database password stops the application with a clear message instead of
 * quietly falling back to a guessable value — a placeholder secret that works
 * in development is the kind of thing that reaches production unnoticed.
 * Defaults are reserved for settings that are merely inconvenient, such as the
 * database port.
 */
class Config
{
    /** An HS256 key shorter than this is within reach of an offline attack. */
    private const MIN_SECRET_LENGTH = 32;

    private const SAME_SITE_VALUES = ['Lax', 'Strict', 'None'];

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    /**
     * Reads a setting that has no safe default.
     *
     * @throws RuntimeException when it is missing
     */
    public static function required(string $key, string $hint = ''): string
    {
        $value = self::get($key);

        if ($value === '') {
            throw new RuntimeException(sprintf(
                '%s is not set.%s',
                $key,
                $hint === '' ? ' Set it in the environment before starting the API.' : ' ' . $hint
            ));
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function jwtSecret(): string
    {
        $secret = self::required('JWT_SECRET', 'Generate one with: openssl rand -hex 32');

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new RuntimeException(sprintf(
                'JWT_SECRET must be at least %d characters. Generate one with: openssl rand -hex 32',
                self::MIN_SECRET_LENGTH
            ));
        }

        return $secret;
    }

    /**
     * Origins permitted to make credentialed requests.
     *
     * Credentialed CORS forbids a wildcard, so this is an explicit allowlist
     * rather than a single value echoed back unchecked. Accepts a
     * comma-separated list for environments with more than one front end.
     *
     * @return list<string>
     */
    public static function corsOrigins(): array
    {
        $raw = self::required(
            'CORS_ORIGIN',
            'Set it to the front end origin, for example https://tasks.example.com'
        );

        $origins = array_filter(array_map('trim', explode(',', $raw)), static function (string $origin): bool {
            return $origin !== '';
        });

        return array_values($origins);
    }

    /**
     * Whether the refresh cookie is marked Secure. Defaults to on: a cookie
     * that travels over plain HTTP is one packet capture away from a session.
     */
    public static function cookieSecure(): bool
    {
        return self::bool('COOKIE_SECURE', true);
    }

    /**
     * SameSite policy for the refresh cookie.
     *
     * Lax suits the common case where the API and the front end share a
     * registrable domain. Serving them from unrelated domains requires None,
     * which browsers only honour on a Secure cookie — so that combination is
     * rejected rather than silently producing a cookie the browser drops.
     */
    public static function cookieSameSite(): string
    {
        $value = ucfirst(strtolower(self::get('COOKIE_SAMESITE', 'Lax')));

        if (!in_array($value, self::SAME_SITE_VALUES, true)) {
            throw new RuntimeException(
                'COOKIE_SAMESITE must be one of: ' . implode(', ', self::SAME_SITE_VALUES)
            );
        }

        if ($value === 'None' && !self::cookieSecure()) {
            throw new RuntimeException(
                'COOKIE_SAMESITE=None requires COOKIE_SECURE=true, otherwise browsers reject the cookie.'
            );
        }

        return $value;
    }
}

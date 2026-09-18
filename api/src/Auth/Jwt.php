<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\UnauthorizedException;

/**
 * Minimal HS256 JWT encoder and verifier.
 *
 * Only HS256 is accepted. The header's own "alg" is checked against that fixed
 * expectation rather than used to pick an algorithm, which is what defeats the
 * two classic JWT forgeries: "alg": "none" (no signature at all) and algorithm
 * confusion, where an attacker re-signs an RS256 token with HS256 using the
 * public key as the HMAC secret.
 */
class Jwt
{
    private const ALGORITHM = 'HS256';
    private const HASH = 'sha256';

    /** @var string */
    private $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => self::ALGORITHM, 'typ' => 'JWT']));
        $payload = self::base64UrlEncode((string) json_encode($claims));

        $signature = self::base64UrlEncode(
            hash_hmac(self::HASH, $header . '.' . $payload, $this->secret, true)
        );

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new UnauthorizedException('Malformed access token.', 'token_invalid');
        }

        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode(self::base64UrlDecode($header), true);

        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== self::ALGORITHM) {
            throw new UnauthorizedException('Unsupported token algorithm.', 'token_invalid');
        }

        $expected = hash_hmac(self::HASH, $header . '.' . $payload, $this->secret, true);

        // hash_equals compares in constant time, so a wrong signature cannot be
        // narrowed down byte by byte by measuring how long the check takes.
        if (!hash_equals($expected, self::base64UrlDecode($signature))) {
            throw new UnauthorizedException('Access token signature is invalid.', 'token_invalid');
        }

        // Claims are only read after the signature checks out.
        $claims = json_decode(self::base64UrlDecode($payload), true);

        if (!is_array($claims)) {
            throw new UnauthorizedException('Malformed access token.', 'token_invalid');
        }

        $now = time();

        if (isset($claims['nbf']) && $now < (int) $claims['nbf']) {
            throw new UnauthorizedException('Access token is not valid yet.', 'token_invalid');
        }

        if (!isset($claims['exp']) || $now >= (int) $claims['exp']) {
            throw new UnauthorizedException('Access token has expired.', 'token_expired');
        }

        return $claims;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new UnauthorizedException('Malformed access token.', 'token_invalid');
        }

        return $decoded;
    }
}

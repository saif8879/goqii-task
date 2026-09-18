<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\UnauthorizedException;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;

/**
 * Issues and rotates the token pair.
 *
 * The access token is a stateless JWT and therefore cannot be revoked, so it is
 * deliberately short-lived. The refresh token is the long-lived, revocable half:
 * it is random, stored only as a SHA-256 hash, and replaced on every use.
 */
class TokenService
{
    /** Access tokens last 15 minutes: short enough that a leaked one is close to worthless. */
    public const ACCESS_TTL = 900;

    /** Refresh tokens last 30 days. */
    public const REFRESH_TTL = 2592000;

    /**
     * How long a just-rotated refresh token keeps working.
     *
     * Rotation assumes one client exchanging one token at a time, but a browser
     * breaks that assumption routinely: two tabs restoring a session on start-up
     * both present the cookie, and a request retried over a flaky connection
     * arrives twice. Treating those as theft logs people out for no reason.
     *
     * Inside this window a replay is accepted as a benign retry, provided the
     * lineage is still alive. Outside it, or once a family has been burned, the
     * strict rule applies. The trade-off is a few seconds in which a stolen
     * token passes undetected, against a session that survives normal browsing.
     */
    public const REPLAY_GRACE = 10;

    /** @var Jwt */
    private $jwt;

    /** @var RefreshTokenRepository */
    private $tokens;

    /** @var UserRepository */
    private $users;

    public function __construct(Jwt $jwt, RefreshTokenRepository $tokens, UserRepository $users)
    {
        $this->jwt = $jwt;
        $this->tokens = $tokens;
        $this->users = $users;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function issueFor(array $user, ?string $familyId = null, ?string $userAgent = null): array
    {
        $now = time();

        $accessToken = $this->jwt->encode([
            'iss'   => 'task-manager-api',
            'sub'   => (int) $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'iat'   => $now,
            'exp'   => $now + self::ACCESS_TTL,
        ]);

        $refreshToken = bin2hex(random_bytes(32));
        $expiresAt = $now + self::REFRESH_TTL;

        $id = $this->tokens->store([
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $refreshToken),
            'family_id'  => $familyId ?? bin2hex(random_bytes(16)),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        unset($user['password_hash']);

        return [
            'id'                 => $id,
            'user'               => $user,
            'access_token'       => $accessToken,
            'expires_in'         => self::ACCESS_TTL,
            'refresh_token'      => $refreshToken,
            'refresh_expires_at' => $expiresAt,
        ];
    }

    /**
     * Exchanges a refresh token for a fresh pair, invalidating the old one.
     *
     * @return array<string, mixed>
     */
    public function rotate(string $refreshToken, ?string $userAgent = null): array
    {
        // The whole exchange runs in one transaction so that revoking the old
        // token and inserting its replacement become visible together. A second
        // request for the same token waits on the row lock below and then sees a
        // finished rotation, which is what makes the decision further down
        // deterministic instead of a race.
        $outcome = $this->tokens->transactional(function () use ($refreshToken, $userAgent) {
            $record = $this->tokens->findByHashForUpdate(hash('sha256', $refreshToken));

            // These two throw because there is nothing to keep: no rows were
            // touched, so rolling back costs nothing.
            if ($record === null) {
                throw new UnauthorizedException('Refresh token is not recognised.', 'refresh_invalid');
            }

            if (strtotime((string) $record['expires_at']) <= time()) {
                throw new UnauthorizedException('Refresh token has expired.', 'refresh_expired');
            }

            $familyId = (string) $record['family_id'];
            $alreadyUsed = $record['revoked_at'] !== null;

            // The failures below revoke rows, so they report back and let the
            // caller raise the error after the commit. Throwing here would roll
            // back the revocation — undoing the very thing that stops the leak.
            if ($alreadyUsed && !$this->isBenignReplay($record, $familyId)) {
                // A copy of this token is circulating, so burn the whole lineage:
                // both the attacker and the legitimate holder must log in again.
                $this->tokens->revokeFamily($familyId);

                return ['error' => 'refresh_reused'];
            }

            $user = $this->users->find((int) $record['user_id']);

            if ($user === null) {
                $this->tokens->revokeFamily($familyId);

                return ['error' => 'account_missing'];
            }

            if (!$alreadyUsed) {
                $this->tokens->revoke((int) $record['id']);
            }

            $issued = $this->issueFor($user, $familyId, $userAgent);

            // The chain records one replacement per token. A grace-period retry
            // adds a sibling and leaves the existing link untouched.
            if (!$alreadyUsed) {
                $this->tokens->recordReplacement((int) $record['id'], (int) $issued['id']);
            }

            return ['issued' => $issued];
        });

        if (isset($outcome['error'])) {
            if ($outcome['error'] === 'refresh_reused') {
                throw new UnauthorizedException('Refresh token has already been used.', 'refresh_reused');
            }

            throw new UnauthorizedException('Account no longer exists.', 'refresh_invalid');
        }

        return $outcome['issued'];
    }

    /**
     * Whether an already-used token is an honest duplicate rather than a replay
     * worth acting on.
     *
     * Both conditions matter. The age check keeps the window narrow, and the
     * live-lineage check means a family already burned by reuse detection stays
     * dead — otherwise an attacker could revive a revoked session by replaying
     * within the window.
     *
     * @param array<string, mixed> $record
     */
    private function isBenignReplay(array $record, string $familyId): bool
    {
        $revokedAt = strtotime((string) $record['revoked_at']);

        if ($revokedAt === false || time() - $revokedAt > self::REPLAY_GRACE) {
            return false;
        }

        return $this->tokens->familyHasLiveToken($familyId);
    }

    public function revoke(string $refreshToken): void
    {
        $record = $this->tokens->findByHash(hash('sha256', $refreshToken));

        if ($record !== null) {
            $this->tokens->revoke((int) $record['id']);
        }
    }
}

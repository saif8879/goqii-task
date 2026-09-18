<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Auth\TokenService;
use App\Exceptions\UnauthorizedException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Validation\AuthValidator;

class AuthController
{
    private const REFRESH_COOKIE = 'refresh_token';

    /**
     * A real bcrypt hash of a value nobody knows. Verifying against it when an
     * email is not registered keeps the failed-login response time roughly
     * constant, so timing cannot be used to enumerate which emails exist.
     */
    private const DUMMY_HASH = '$2y$10$wHSOEc.ttpP0AQLut4Wcr.yagyFIYpOlvlhp916ehEYtwD8DvWali';

    /** @var UserRepository */
    private $users;

    /** @var TokenService */
    private $tokens;

    /** @var RefreshTokenRepository */
    private $refreshTokens;

    /** @var AuthValidator */
    private $validator;

    public function __construct(
        UserRepository $users,
        TokenService $tokens,
        RefreshTokenRepository $refreshTokens,
        AuthValidator $validator
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->refreshTokens = $refreshTokens;
        $this->validator = $validator;
    }

    public function register(Request $request): void
    {
        $data = $this->validator->validateRegistration($request->body());

        $id = $this->users->create(
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT)
        );

        $user = $this->users->find($id);

        $this->respondWithTokens($user, $this->tokens->issueFor($user, null, $request->header('user-agent')), 201);
    }

    public function login(Request $request): void
    {
        $data = $this->validator->validateLogin($request->body());

        $user = $this->users->findByEmail($data['email']);

        if ($user === null) {
            password_verify($data['password'], self::DUMMY_HASH);

            // Never reveal which half was wrong.
            throw new UnauthorizedException('Invalid email or password.', 'invalid_credentials');
        }

        if (!password_verify($data['password'], (string) $user['password_hash'])) {
            throw new UnauthorizedException('Invalid email or password.', 'invalid_credentials');
        }

        // Transparently upgrade the stored hash if PHP's default cost has moved on.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash(
                (int) $user['id'],
                password_hash($data['password'], PASSWORD_DEFAULT)
            );
        }

        unset($user['password_hash']);

        $this->respondWithTokens($user, $this->tokens->issueFor($user, null, $request->header('user-agent')));
    }

    public function refresh(Request $request): void
    {
        $refreshToken = $request->cookie(self::REFRESH_COOKIE);

        if ($refreshToken === null) {
            throw new UnauthorizedException('No refresh token was provided.', 'refresh_missing');
        }

        $issued = $this->tokens->rotate($refreshToken, $request->header('user-agent'));

        $this->respondWithTokens($issued['user'], $issued);
    }

    public function logout(Request $request): void
    {
        $refreshToken = $request->cookie(self::REFRESH_COOKIE);

        if ($refreshToken !== null) {
            $this->tokens->revoke($refreshToken);
        }

        $this->clearRefreshCookie();

        Response::noContent();
    }

    /**
     * @param array<string, string> $params
     */
    public function me(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $user = $this->users->find($actor->id());

        if ($user === null) {
            throw new UnauthorizedException('Account no longer exists.', 'token_invalid');
        }

        Response::json(['data' => $user]);
    }

    /**
     * Revokes every refresh token for the caller, which signs them out of all
     * devices rather than just the current one.
     *
     * @param array<string, string> $params
     */
    public function logoutEverywhere(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $this->refreshTokens->revokeAllForUser($actor->id());
        $this->clearRefreshCookie();

        Response::noContent();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $issued
     */
    private function respondWithTokens(array $user, array $issued, int $status = 200): void
    {
        $this->sendRefreshCookie($issued['refresh_token'], (int) $issued['refresh_expires_at']);

        Response::json([
            'data' => [
                'user'         => $user,
                'access_token' => $issued['access_token'],
                'token_type'   => 'Bearer',
                'expires_in'   => $issued['expires_in'],
            ],
        ], $status);
    }

    private function sendRefreshCookie(string $value, int $expiresAt): void
    {
        setcookie(self::REFRESH_COOKIE, $value, self::cookieOptions($expiresAt));
    }

    private function clearRefreshCookie(): void
    {
        setcookie(self::REFRESH_COOKIE, '', self::cookieOptions(time() - 3600));
    }

    /**
     * @return array<string, mixed>
     */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            // Scoped to the auth routes, so it is not attached to every task request.
            'path'     => '/api/auth',
            // Unreadable from JavaScript, which is what makes it a safer place
            // for the long-lived half of the pair than the access token.
            'httponly' => true,
            'samesite' => Config::cookieSameSite(),
            'secure'   => Config::cookieSecure(),
        ];
    }
}

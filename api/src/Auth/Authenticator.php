<?php

declare(strict_types=1);

namespace App\Auth;

use App\Exceptions\UnauthorizedException;
use App\Http\Request;

class Authenticator
{
    /** @var Jwt */
    private $jwt;

    public function __construct(Jwt $jwt)
    {
        $this->jwt = $jwt;
    }

    /**
     * Resolves the bearer token into a user, or fails with 401. The router only
     * calls this for protected routes, so a stale Authorization header cannot
     * block a public one such as login.
     */
    public function requireUser(Request $request): AuthenticatedUser
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new UnauthorizedException('Authentication is required.', 'no_token');
        }

        $claims = $this->jwt->decode($token);

        if (!isset($claims['sub'], $claims['email'], $claims['role'])) {
            throw new UnauthorizedException('Access token is missing required claims.', 'token_invalid');
        }

        return new AuthenticatedUser((int) $claims['sub'], (string) $claims['email'], (string) $claims['role']);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthenticatedUser
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    /** @var int */
    private $id;

    /** @var string */
    private $email;

    /** @var string */
    private $role;

    public function __construct(int $id, string $email, string $role)
    {
        $this->id = $id;
        $this->email = $email;
        $this->role = $role;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}

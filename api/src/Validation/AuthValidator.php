<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;

class AuthValidator
{
    private const NAME_MIN = 2;
    private const NAME_MAX = 100;
    private const EMAIL_MAX = 190;
    private const PASSWORD_MIN = 8;

    /**
     * bcrypt only considers the first 72 bytes of input, so anything longer is
     * silently truncated. Rejecting it outright is clearer than accepting a
     * password where the tail does not actually matter.
     */
    private const PASSWORD_MAX = 72;

    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, email: string, password: string}
     */
    public function validateRegistration(array $input): array
    {
        $errors = [];

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) < self::NAME_MIN) {
            $errors['name'] = sprintf('Name must be at least %d characters.', self::NAME_MIN);
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('Name cannot be longer than %d characters.', self::NAME_MAX);
        }

        $email = $this->normaliseEmail($input['email'] ?? null);

        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (strlen($email) > self::EMAIL_MAX) {
            $errors['email'] = sprintf('Email cannot be longer than %d characters.', self::EMAIL_MAX);
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'That email is already registered.';
        }

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (mb_strlen($password) < self::PASSWORD_MIN) {
            $errors['password'] = sprintf('Password must be at least %d characters.', self::PASSWORD_MIN);
        } elseif (strlen($password) > self::PASSWORD_MAX) {
            $errors['password'] = sprintf('Password cannot be longer than %d bytes.', self::PASSWORD_MAX);
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors['password'] = 'Password must contain at least one letter and one number.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{email: string, password: string}
     */
    public function validateLogin(array $input): array
    {
        $errors = [];

        $email = $this->normaliseEmail($input['email'] ?? null);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($email === '') {
            $errors['email'] = 'Email is required.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['email' => $email, 'password' => $password];
    }

    /**
     * @param mixed $value
     */
    private function normaliseEmail($value): string
    {
        // Lower-casing on the way in keeps one account per address rather than
        // letting Saif@example.com and saif@example.com become two users.
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }
}

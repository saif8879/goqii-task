#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Creates an administrator account.
 *
 * Registration through the API always produces a regular user, by design, so
 * the first admin of a fresh deployment has to be made here.
 *
 *   php scripts/create-admin.php
 *
 * Runs interactively, or reads ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD from
 * the environment for scripted deployments.
 */

use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use App\Support\Env;
use App\Validation\AuthValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../api/src/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/**
 * Reads a line from standard input, optionally without echoing it.
 */
function ask(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);

    // Turning off terminal echo keeps the password out of the screen and the
    // shell's scrollback. Restored immediately, including on failure.
    $canHide = $hidden && PHP_OS_FAMILY !== 'Windows';

    if ($canHide) {
        system('stty -echo 2>/dev/null');
    }

    try {
        $line = fgets(STDIN);
    } finally {
        if ($canHide) {
            system('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return $line === false ? '' : trim($line);
}

function fromEnvOrAsk(string $variable, string $label, bool $hidden = false): string
{
    $value = getenv($variable);

    if ($value !== false && $value !== '') {
        return $value;
    }

    return ask($label, $hidden);
}

try {
    $users = new UserRepository();

    $existingAdmins = array_filter($users->all(), static function (array $user): bool {
        return $user['role'] === 'admin';
    });

    if ($existingAdmins !== []) {
        fwrite(STDOUT, sprintf(
            "This database already has %d administrator account(s):\n",
            count($existingAdmins)
        ));

        foreach ($existingAdmins as $admin) {
            fwrite(STDOUT, sprintf("  - %s <%s>\n", $admin['name'], $admin['email']));
        }

        fwrite(STDOUT, "\n");
    }

    $name = fromEnvOrAsk('ADMIN_NAME', 'Name:     ');
    $email = fromEnvOrAsk('ADMIN_EMAIL', 'Email:    ');
    $password = fromEnvOrAsk('ADMIN_PASSWORD', 'Password: ', true);

    // Reuse the API's own rules so a bootstrapped admin cannot end up with a
    // weaker password than a self-registered user.
    $validator = new AuthValidator($users);

    $clean = $validator->validateRegistration([
        'name'     => $name,
        'email'    => $email,
        'password' => $password,
    ]);

    $id = $users->createAdmin(
        $clean['name'],
        $clean['email'],
        password_hash($clean['password'], PASSWORD_DEFAULT)
    );

    fwrite(STDOUT, sprintf("\nCreated administrator #%d <%s>.\n", $id, $clean['email']));

    exit(0);
} catch (ValidationException $e) {
    fwrite(STDERR, "\nThat could not be accepted:\n");

    foreach ($e->errors() as $field => $message) {
        fwrite(STDERR, sprintf("  %-9s %s\n", $field . ':', $message));
    }

    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");

    exit(1);
}

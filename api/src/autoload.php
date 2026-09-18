<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 style autoloader for the App namespace. The project has no
 * third-party dependencies, so pulling in Composer would only add noise.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

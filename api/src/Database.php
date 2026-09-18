<?php

declare(strict_types=1);

namespace App;

use App\Support\Config;
use PDO;
use PDOException;
use RuntimeException;

class Database
{
    /** @var PDO|null */
    private static $connection;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect();
        }

        return self::$connection;
    }

    private static function connect(): PDO
    {
        // Host and port have harmless defaults. The database name and
        // credentials do not: falling back to a stock username and password is
        // how an application ends up connecting somewhere nobody intended.
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_PORT', '3306'),
            Config::required('DB_NAME')
        );

        try {
            return new PDO($dsn, Config::required('DB_USER'), Config::required('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Use real prepared statements so the driver, not PHP, escapes values.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Driver messages can quote the DSN, so the detail goes to the log
            // and the caller gets something safe to surface.
            error_log('Database connection failed: ' . $e->getMessage());

            throw new RuntimeException('Could not connect to the database.', 0, $e);
        }
    }
}

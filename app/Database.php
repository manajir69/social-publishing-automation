<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = require BASE_PATH . '/config/database.php';
        if ($db['name'] === '' || $db['user'] === '') {
            throw new \RuntimeException('Database configuration is incomplete.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // MySQL otherwise uses the hosting server's timezone for NOW(),
        // CURRENT_TIMESTAMP and automatic timestamp columns. Keep each
        // connection aligned with the application's configured timezone so
        // publish, notification, retry and audit times are stored consistently.
        $timezone = new \DateTimeZone((string) config('timezone', 'Asia/Dhaka'));
        $offsetSeconds = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));
        $absoluteOffset = abs($offsetSeconds);
        $offset = sprintf(
            '%s%02d:%02d',
            $offsetSeconds < 0 ? '-' : '+',
            intdiv($absoluteOffset, 3600),
            intdiv($absoluteOffset % 3600, 60)
        );
        self::$pdo->exec('SET time_zone = ' . self::$pdo->quote($offset));

        return self::$pdo;
    }
}

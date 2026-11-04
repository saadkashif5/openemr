<?php

declare(strict_types=1);

namespace Platform\Dashboard;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $db = $config->db;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
            $db['user'],
            $db['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Every timestamp the dashboard stores or compares is UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}

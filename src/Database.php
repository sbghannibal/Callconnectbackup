<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        return new PDO($config->dsn(), $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Creates the database (if needed) and applies database/schema.sql.
     */
    public static function migrate(Config $config): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort);
        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $config->dbName)
        ));

        $pdo = self::connect($config);
        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Unable to read database/schema.sql');
        }
        $pdo->exec($sql);
    }
}

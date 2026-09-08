<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database;
use App\Repository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real MySQL database. The tests are skipped
 * when no MySQL server is reachable.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Config $config;
    protected PDO $pdo;
    protected Repository $repository;

    protected function setUp(): void
    {
        $this->config = new Config(
            dbHost: getenv('TEST_DB_HOST') ?: '127.0.0.1',
            dbPort: (int) (getenv('TEST_DB_PORT') ?: 3306),
            dbName: getenv('TEST_DB_NAME') ?: 'callconnect_test',
            dbUser: getenv('TEST_DB_USER') ?: 'root',
            dbPassword: getenv('TEST_DB_PASSWORD') ?: 'root',
            username: 'tester',
            password: 'secret',
        );

        try {
            Database::migrate($this->config);
            $this->pdo = Database::connect($this->config);
        } catch (\PDOException $exception) {
            $this->markTestSkipped('MySQL not available: ' . $exception->getMessage());
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['parameters', 'records', 'login_log', 'push_log', 'discovery_fields', 'discovery_pages', 'discovery_runs'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->repository = new Repository($this->pdo);
    }
}

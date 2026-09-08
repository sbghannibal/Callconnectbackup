<?php

declare(strict_types=1);

use App\Config;
use App\Database;

require_once __DIR__ . '/../src/autoload.php';

Config::loadDotEnv(dirname(__DIR__) . '/.env');

$config = Config::fromEnv();
Database::migrate($config);

printf("Database '%s' is up to date.\n", $config->dbName);

<?php

declare(strict_types=1);

use App\Config;
use App\Database;
use App\HttpCallConnectClient;
use App\Repository;

require_once __DIR__ . '/autoload.php';

Config::loadDotEnv(dirname(__DIR__) . '/.env');

/**
 * @return array{0: Config, 1: Repository, 2: HttpCallConnectClient}
 */
function bootstrap(): array
{
    $config = Config::fromEnv();
    $repository = new Repository(Database::connect($config));
    $client = new HttpCallConnectClient($config);

    return [$config, $repository, $client];
}

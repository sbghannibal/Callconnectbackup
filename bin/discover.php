<?php

declare(strict_types=1);

use App\DiscoveryService;

require_once __DIR__ . '/../src/bootstrap.php';

[$config, $repository, $client] = bootstrap();

$result = (new DiscoveryService($repository, $client, $config))->run();

printf("%s\n", $result['message']);
exit($result['success'] ? 0 : 1);

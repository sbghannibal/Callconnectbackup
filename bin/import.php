<?php

declare(strict_types=1);

use App\ImportService;

require_once __DIR__ . '/../src/bootstrap.php';

[$config, $repository, $client] = bootstrap();

$result = (new ImportService($repository, $client, $config))->run();

printf("%s\n", $result['message']);
exit($result['success'] ? 0 : 1);

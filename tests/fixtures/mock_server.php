<?php

declare(strict_types=1);

/**
 * Minimal CallConnect look-alike used by the HTTP client test and for local
 * demos: php -S 127.0.0.1:8081 tests/fixtures/mock_server.php
 */

session_start();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

$storeFile = sys_get_temp_dir() . '/callconnect_mock_records.json';
$records = is_file($storeFile)
    ? json_decode((string) file_get_contents($storeFile), true)
    : [
        'A1' => ['id' => 'A1', 'name' => 'Reception', 'destination' => '022001000'],
        'A2' => ['id' => 'A2', 'name' => 'Support', 'destination' => '022001001'],
    ];

header('Content-Type: application/json');

if ($path === '/api/login' && $method === 'POST') {
    if (($body['username'] ?? '') === 'tester' && ($body['password'] ?? '') === 'secret') {
        $_SESSION['authenticated'] = true;
        echo json_encode(['message' => 'Login successful']);
        exit;
    }

    http_response_code(401);
    echo json_encode(['message' => 'Invalid credentials']);
    exit;
}

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['message' => 'Not authenticated']);
    exit;
}

// HTML pages of the portal, used by the discovery crawler tests.
$pages = [
    '/portal/users' => 'users.html',
    '/portal/users/A1' => 'user_detail.html',
    '/portal/users/A2' => 'user_detail_a2.html',
    '/admin/system' => 'admin_system.html',
];

if (isset($pages[(string) $path]) && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pages/' . $pages[(string) $path]);
    exit;
}

if ($path === '/api/subscribers' && $method === 'GET') {
    echo json_encode(['items' => array_values($records)]);
    exit;
}

if (preg_match('#^/api/subscribers/([^/]+)$#', (string) $path, $matches) === 1 && $method === 'PUT') {
    $id = rawurldecode($matches[1]);
    if (!isset($records[$id])) {
        http_response_code(404);
        echo json_encode(['message' => 'Unknown record']);
        exit;
    }

    $records[$id] = array_merge($records[$id], $body);
    file_put_contents($storeFile, json_encode($records));
    echo json_encode(['message' => 'Updated', 'item' => $records[$id]]);
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);

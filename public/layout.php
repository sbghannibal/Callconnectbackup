<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    return (string) $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * @return array<int, array{type: string, message: string}>
 */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $flashes;
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function render_header(string $title, string $active): void
{
    $flashes = take_flashes();
    ?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> &middot; CallConnect Backup</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <h1>CallConnect Backup</h1>
    <nav>
        <a href="index.php"<?= $active === 'data' ? ' class="active"' : '' ?>>Data &amp; parameters</a>
        <a href="discovery.php"<?= $active === 'discovery' ? ' class="active"' : '' ?>>Ontdekking</a>
        <a href="logs.php"<?= $active === 'logs' ? ' class="active"' : '' ?>>Logboek</a>
    </nav>
</header>
<main>
    <?php foreach ($flashes as $item): ?>
        <p class="flash flash-<?= e($item['type']) ?>"><?= e($item['message']) ?></p>
    <?php endforeach; ?>
<?php
}

function render_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

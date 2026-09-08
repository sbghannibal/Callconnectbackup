<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the App\ namespace, so the tool runs without
 * Composer (only PHP with ext-pdo_mysql, ext-curl and ext-json is required).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

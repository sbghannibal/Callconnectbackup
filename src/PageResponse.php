<?php

declare(strict_types=1);

namespace App;

/**
 * Result of a raw GET request on a CallConnect page. Unlike ApiResponse the
 * body is kept as-is, because discovery has to parse HTML (and sometimes JSON
 * embedded in that HTML).
 */
final class PageResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $url = '',
        public readonly ?int $statusCode = null,
        public readonly string $body = '',
        public readonly string $contentType = '',
        public readonly string $message = '',
    ) {
    }
}

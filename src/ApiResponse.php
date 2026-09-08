<?php

declare(strict_types=1);

namespace App;

final class ApiResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $statusCode = null,
        public readonly string $message = '',
        public readonly array $data = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\ApiResponse;
use App\CallConnectClientInterface;

/**
 * In-memory CallConnect double used by the service tests.
 */
final class FakeCallConnectClient implements CallConnectClientInterface
{
    /** @var array<int, array{externalId: string, payload: array<string, string>}> */
    public array $pushes = [];
    public int $loginCalls = 0;

    public function __construct(
        private readonly bool $loginSucceeds = true,
        private readonly array $records = [],
        private readonly bool $pushSucceeds = true,
    ) {
    }

    public function login(string $username, string $password): ApiResponse
    {
        $this->loginCalls++;

        return $this->loginSucceeds
            ? new ApiResponse(true, 200, 'Login successful')
            : new ApiResponse(false, 401, 'Invalid credentials');
    }

    public function fetchRecords(): ApiResponse
    {
        return new ApiResponse(true, 200, 'HTTP 200', $this->records);
    }

    public function pushRecord(string $externalId, array $payload): ApiResponse
    {
        $this->pushes[] = ['externalId' => $externalId, 'payload' => $payload];

        return $this->pushSucceeds
            ? new ApiResponse(true, 200, 'HTTP 200')
            : new ApiResponse(false, 500, 'Server error');
    }
}

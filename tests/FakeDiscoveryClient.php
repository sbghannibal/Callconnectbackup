<?php

declare(strict_types=1);

namespace App\Tests;

use App\ApiResponse;
use App\CallConnectClientInterface;
use App\PageFetcherInterface;
use App\PageResponse;

/**
 * CallConnect double that serves fixture HTML pages, used by the discovery
 * tests. The keys of $pages are paths relative to the base URL.
 */
final class FakeDiscoveryClient implements CallConnectClientInterface, PageFetcherInterface
{
    /** @var array<int, string> */
    public array $requested = [];

    /**
     * @param array<string, string> $pages path => HTML
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $pages,
        private readonly bool $loginSucceeds = true,
    ) {
    }

    public function login(string $username, string $password): ApiResponse
    {
        return $this->loginSucceeds
            ? new ApiResponse(true, 200, 'Login successful')
            : new ApiResponse(false, 401, 'Invalid credentials');
    }

    public function fetchRecords(): ApiResponse
    {
        return new ApiResponse(true, 200, 'HTTP 200');
    }

    public function pushRecord(string $externalId, array $payload): ApiResponse
    {
        return new ApiResponse(true, 200, 'HTTP 200');
    }

    public function fetchPage(string $pathOrUrl): PageResponse
    {
        $path = str_starts_with($pathOrUrl, $this->baseUrl)
            ? substr($pathOrUrl, strlen($this->baseUrl))
            : $pathOrUrl;
        $this->requested[] = $path;

        if (!isset($this->pages[$path])) {
            return new PageResponse(false, $pathOrUrl, 404, 'not found', 'text/html', 'HTTP 404');
        }

        return new PageResponse(true, $pathOrUrl, 200, $this->pages[$path], 'text/html', 'HTTP 200');
    }
}

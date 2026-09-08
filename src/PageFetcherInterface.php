<?php

declare(strict_types=1);

namespace App;

/**
 * Clients that can retrieve a raw page (HTML or JSON) with an authenticated
 * GET request. Used by the discovery crawler.
 */
interface PageFetcherInterface
{
    /**
     * @param string $pathOrUrl absolute URL or a path relative to the base URL
     */
    public function fetchPage(string $pathOrUrl): PageResponse;
}

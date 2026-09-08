<?php

declare(strict_types=1);

namespace App;

interface CallConnectClientInterface
{
    /**
     * Authenticates against the CallConnect portal.
     */
    public function login(string $username, string $password): ApiResponse;

    /**
     * Fetches all records. ApiResponse::$data contains a list of associative
     * arrays as returned by the portal.
     */
    public function fetchRecords(): ApiResponse;

    /**
     * Pushes changed parameters of a single record back to CallConnect.
     *
     * @param array<string, string> $payload
     */
    public function pushRecord(string $externalId, array $payload): ApiResponse;
}

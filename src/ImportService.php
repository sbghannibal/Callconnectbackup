<?php

declare(strict_types=1);

namespace App;

/**
 * Logs in to CallConnect, fetches all records and stores them in MySQL.
 * Every login attempt (successful or not) is written to the login_log table.
 */
final class ImportService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly CallConnectClientInterface $client,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{success: bool, message: string, imported: int}
     */
    public function run(): array
    {
        $login = $this->authenticate();
        if (!$login->success) {
            return [
                'success' => false,
                'message' => 'Login to CallConnect failed: ' . $login->message,
                'imported' => 0,
            ];
        }

        $response = $this->client->fetchRecords();
        if (!$response->success) {
            return [
                'success' => false,
                'message' => 'Fetching data from CallConnect failed: ' . $response->message,
                'imported' => 0,
            ];
        }

        $imported = 0;
        foreach ($response->data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $externalId = $this->externalId($item);
            if ($externalId === null) {
                continue;
            }

            $this->repository->upsertRecord(
                $externalId,
                (string) ($item[$this->config->labelField] ?? $externalId),
                $this->parameters($item),
                $item
            );
            $imported++;
        }

        return [
            'success' => true,
            'message' => sprintf('%d record(s) imported from CallConnect', $imported),
            'imported' => $imported,
        ];
    }

    private function authenticate(): ApiResponse
    {
        $attemptId = $this->repository->startLoginAttempt($this->config->username);
        $response = $this->client->login($this->config->username, $this->config->password);
        $this->repository->finishLoginAttempt(
            $attemptId,
            $response->success,
            $response->statusCode,
            $response->message !== '' ? $response->message : ($response->success ? 'Login successful' : 'Login failed')
        );

        return $response;
    }

    private function externalId(array $item): ?string
    {
        $value = $item[$this->config->idField] ?? null;
        if ($value === null || is_array($value) || (string) $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Only scalar fields become editable parameters; the complete item is kept
     * in records.raw_json.
     *
     * @return array<string, string>
     */
    private function parameters(array $item): array
    {
        $parameters = [];
        foreach ($item as $name => $value) {
            if ((string) $name === $this->config->idField || is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $parameters[(string) $name] = $value === null ? '' : (string) $value;
        }

        return $parameters;
    }
}

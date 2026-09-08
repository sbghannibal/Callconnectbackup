<?php

declare(strict_types=1);

namespace App;

/**
 * Pushes the parameters that were marked in the portal back to CallConnect.
 * Both the login attempt and every push are logged.
 */
final class PushService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly CallConnectClientInterface $client,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{success: bool, message: string, pushed: int, failed: int}
     */
    public function run(): array
    {
        $selected = $this->repository->listSelectedParameters();
        if ($selected === []) {
            return [
                'success' => true,
                'message' => 'No parameters marked for push',
                'pushed' => 0,
                'failed' => 0,
            ];
        }

        $login = $this->authenticate();
        if (!$login->success) {
            return [
                'success' => false,
                'message' => 'Login to CallConnect failed: ' . $login->message,
                'pushed' => 0,
                'failed' => 0,
            ];
        }

        $grouped = [];
        foreach ($selected as $parameter) {
            $grouped[(string) $parameter['external_id']][] = $parameter;
        }

        $pushed = 0;
        $failed = 0;
        foreach ($grouped as $externalId => $parameters) {
            $payload = [];
            foreach ($parameters as $parameter) {
                $payload[(string) $parameter['name']] = (string) $parameter['local_value'];
            }

            $response = $this->client->pushRecord((string) $externalId, $payload);
            $this->repository->logPush(
                (string) $externalId,
                $payload,
                $response->success,
                $response->statusCode,
                $response->message
            );

            foreach ($parameters as $parameter) {
                if ($response->success) {
                    $this->repository->markParameterPushed((int) $parameter['id']);
                    $pushed++;
                } else {
                    $this->repository->markParameterFailed((int) $parameter['id']);
                    $failed++;
                }
            }
        }

        return [
            'success' => $failed === 0,
            'message' => sprintf('%d parameter(s) pushed, %d failed', $pushed, $failed),
            'pushed' => $pushed,
            'failed' => $failed,
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
}

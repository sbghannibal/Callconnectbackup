<?php

declare(strict_types=1);

namespace App;

/**
 * cURL based client for the CallConnect portal. The endpoints, the login body
 * format and the field names are all configurable, because they can differ per
 * CallConnect environment.
 */
final class HttpCallConnectClient implements CallConnectClientInterface
{
    private ?string $cookieFile = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function __destruct()
    {
        if ($this->cookieFile !== null && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function login(string $username, string $password): ApiResponse
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'callconnect_');
        if ($this->cookieFile === false) {
            $this->cookieFile = null;

            return new ApiResponse(false, null, 'Unable to create a cookie jar for the CallConnect session');
        }

        $body = [
            $this->config->usernameField => $username,
            $this->config->passwordField => $password,
        ];

        $response = $this->request('POST', $this->config->loginPath, $body);
        if (!$response->success) {
            return $response;
        }

        return new ApiResponse(true, $response->statusCode, 'Login successful', $response->data);
    }

    public function fetchRecords(): ApiResponse
    {
        $response = $this->request('GET', $this->config->dataPath);
        if (!$response->success) {
            return $response;
        }

        return new ApiResponse(true, $response->statusCode, $response->message, $this->extractItems($response->data));
    }

    public function pushRecord(string $externalId, array $payload): ApiResponse
    {
        $path = str_replace('{id}', rawurlencode($externalId), $this->config->pushPath);

        return $this->request($this->config->pushMethod, $path, $payload);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body = null): ApiResponse
    {
        $url = $this->config->baseUrl . '/' . ltrim($path, '/');
        $handle = curl_init();
        $headers = ['Accept: application/json'];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->config->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($this->cookieFile !== null) {
            $options[CURLOPT_COOKIEJAR] = $this->cookieFile;
            $options[CURLOPT_COOKIEFILE] = $this->cookieFile;
        }

        if ($body !== null) {
            if ($this->config->loginFormat === 'form') {
                $options[CURLOPT_POSTFIELDS] = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            return new ApiResponse(false, null, $error !== '' ? $error : 'Request failed');
        }

        $decoded = json_decode((string) $raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : sprintf('HTTP %d', $statusCode);

            return new ApiResponse(false, $statusCode, $message, $data);
        }

        return new ApiResponse(true, $statusCode, sprintf('HTTP %d', $statusCode), $data);
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $data): array
    {
        $key = $this->config->dataKey;
        if ($key !== '' && isset($data[$key]) && is_array($data[$key])) {
            $data = $data[$key];
        }

        return array_values(array_filter($data, 'is_array'));
    }
}

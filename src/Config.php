<?php

declare(strict_types=1);

namespace App;

/**
 * Runtime configuration, read from environment variables (optionally provided
 * through a .env file in the project root).
 */
final class Config
{
    public function __construct(
        public readonly string $dbHost = '127.0.0.1',
        public readonly int $dbPort = 3306,
        public readonly string $dbName = 'callconnect',
        public readonly string $dbUser = 'root',
        public readonly string $dbPassword = '',
        public readonly string $baseUrl = 'https://callconnect.proximus.be',
        public readonly string $loginPath = '/api/login',
        public readonly string $dataPath = '/api/subscribers',
        public readonly string $pushPath = '/api/subscribers/{id}',
        public readonly string $pushMethod = 'PUT',
        public readonly string $loginFormat = 'json',
        public readonly string $usernameField = 'username',
        public readonly string $passwordField = 'password',
        public readonly string $dataKey = 'items',
        public readonly string $idField = 'id',
        public readonly string $labelField = 'name',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly int $timeout = 30,
        public readonly string $discoverySeedPaths = '',
        public readonly string $discoveryAllowPatterns = '',
        public readonly int $discoveryMaxDepth = 2,
        public readonly int $discoveryMaxPages = 50,
    ) {
    }

    /**
     * Pages the crawler starts from. Falls back to the configured data path.
     *
     * @return array<int, string>
     */
    public function seedPaths(): array
    {
        $paths = self::splitList($this->discoverySeedPaths);

        return $paths === [] ? array_values(array_filter([$this->dataPath])) : $paths;
    }

    /**
     * Path patterns the crawler is allowed to visit ("*" is a wildcard).
     * An empty list means: everything inside the base URL.
     *
     * @return array<int, string>
     */
    public function allowPatterns(): array
    {
        return self::splitList($this->discoveryAllowPatterns);
    }

    /**
     * @return array<int, string>
     */
    private static function splitList(string $value): array
    {
        $items = array_map('trim', preg_split('/[,\n]/', $value) ?: []);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public static function fromEnv(array $env = null): self
    {
        $env ??= array_merge($_ENV, $_SERVER, getenv());
        $get = static function (string $key, string $default) use ($env): string {
            $value = $env[$key] ?? getenv($key);
            return ($value === false || $value === null || $value === '') ? $default : (string) $value;
        };

        return new self(
            dbHost: $get('DB_HOST', '127.0.0.1'),
            dbPort: (int) $get('DB_PORT', '3306'),
            dbName: $get('DB_NAME', 'callconnect'),
            dbUser: $get('DB_USER', 'root'),
            dbPassword: $get('DB_PASSWORD', ''),
            baseUrl: rtrim($get('CALLCONNECT_BASE_URL', 'https://callconnect.proximus.be'), '/'),
            loginPath: $get('CALLCONNECT_LOGIN_PATH', '/api/login'),
            dataPath: $get('CALLCONNECT_DATA_PATH', '/api/subscribers'),
            pushPath: $get('CALLCONNECT_PUSH_PATH', '/api/subscribers/{id}'),
            pushMethod: strtoupper($get('CALLCONNECT_PUSH_METHOD', 'PUT')),
            loginFormat: strtolower($get('CALLCONNECT_LOGIN_FORMAT', 'json')),
            usernameField: $get('CALLCONNECT_USERNAME_FIELD', 'username'),
            passwordField: $get('CALLCONNECT_PASSWORD_FIELD', 'password'),
            dataKey: $get('CALLCONNECT_DATA_KEY', 'items'),
            idField: $get('CALLCONNECT_ID_FIELD', 'id'),
            labelField: $get('CALLCONNECT_LABEL_FIELD', 'name'),
            username: $get('CALLCONNECT_USERNAME', ''),
            password: $get('CALLCONNECT_PASSWORD', ''),
            timeout: (int) $get('CALLCONNECT_TIMEOUT', '30'),
            discoverySeedPaths: $get('CALLCONNECT_DISCOVERY_SEEDS', ''),
            discoveryAllowPatterns: $get('CALLCONNECT_DISCOVERY_ALLOW', ''),
            discoveryMaxDepth: (int) $get('CALLCONNECT_DISCOVERY_MAX_DEPTH', '2'),
            discoveryMaxPages: (int) $get('CALLCONNECT_DISCOVERY_MAX_PAGES', '50'),
        );
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->dbHost, $this->dbPort, $this->dbName);
    }

    /**
     * Loads a simple KEY=value .env file into the environment, without
     * overwriting variables that are already set.
     */
    public static function loadDotEnv(string $file): void
    {
        if (!is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App;

/**
 * Crawls the CallConnect portal after login: it starts at the configured seed
 * paths, follows internal links, tabs and form actions (breadth first) and
 * stores every page with all extracted sections, labels, values and form
 * fields in the database, so the team can decide later which fields are
 * really needed.
 *
 * The crawler never leaves the configured base URL and honours an optional
 * allowlist of path patterns plus a depth and page limit.
 */
final class DiscoveryService
{
    private readonly PageParser $parser;

    public function __construct(
        private readonly Repository $repository,
        private readonly CallConnectClientInterface&PageFetcherInterface $client,
        private readonly Config $config,
        ?PageParser $parser = null,
    ) {
        $this->parser = $parser ?? new PageParser();
    }

    /**
     * @return array{success: bool, message: string, pages: int, fields: int, run_id: int|null}
     */
    public function run(): array
    {
        $seeds = $this->config->seedPaths();
        if ($seeds === []) {
            return [
                'success' => false,
                'message' => 'No seed paths configured for discovery',
                'pages' => 0,
                'fields' => 0,
                'run_id' => null,
            ];
        }

        $login = $this->authenticate();
        if (!$login->success) {
            return [
                'success' => false,
                'message' => 'Login to CallConnect failed: ' . $login->message,
                'pages' => 0,
                'fields' => 0,
                'run_id' => null,
            ];
        }

        $runId = $this->repository->startDiscoveryRun($seeds);
        $base = rtrim($this->config->baseUrl, '/');
        $allow = $this->config->allowPatterns();
        $maxPages = max(1, $this->config->discoveryMaxPages);
        $maxDepth = max(0, $this->config->discoveryMaxDepth);

        /** @var array<int, array{url: string, depth: int}> $queue */
        $queue = [];
        $seen = [];
        foreach ($seeds as $seed) {
            $url = Url::resolve($base . '/', $seed);
            if ($url !== '' && !isset($seen[$url])) {
                $seen[$url] = true;
                $queue[] = ['url' => $url, 'depth' => 0];
            }
        }

        $pages = 0;
        $fields = 0;
        $failed = 0;

        while ($queue !== [] && $pages < $maxPages) {
            $current = array_shift($queue);
            $response = $this->client->fetchPage($current['url']);
            $path = Url::pathOf($base, $current['url']);

            if (!$response->success) {
                $failed++;
                $this->repository->saveDiscoveredPage($runId, [
                    'url' => $current['url'],
                    'path' => $path,
                    'title' => '',
                    'depth' => $current['depth'],
                    'status_code' => $response->statusCode,
                    'content_type' => $response->contentType,
                    'raw_html' => $response->body,
                ], []);
                $pages++;
                continue;
            }

            $parsed = $this->parser->parse($response->body, $current['url']);
            $this->repository->saveDiscoveredPage($runId, [
                'url' => $current['url'],
                'path' => $path,
                'title' => $parsed->title,
                'depth' => $current['depth'],
                'status_code' => $response->statusCode,
                'content_type' => $response->contentType,
                'tabs' => $parsed->tabs,
                'links' => $parsed->links,
                'forms' => $parsed->forms,
                'raw_html' => $response->body,
            ], $parsed->fields);

            $pages++;
            $fields += count($parsed->fields);

            if ($current['depth'] >= $maxDepth) {
                continue;
            }

            foreach ($this->followableUrls($parsed, $current['url'], $base) as $next) {
                if (isset($seen[$next]) || !$this->mayVisit($base, $next, $allow)) {
                    continue;
                }
                $seen[$next] = true;
                $queue[] = ['url' => $next, 'depth' => $current['depth'] + 1];
            }
        }

        $message = sprintf(
            '%d page(s) discovered, %d field(s) extracted%s',
            $pages,
            $fields,
            $failed > 0 ? sprintf(', %d page(s) could not be retrieved', $failed) : ''
        );
        $this->repository->finishDiscoveryRun($runId, true, $pages, $fields, $message);

        return [
            'success' => true,
            'message' => $message,
            'pages' => $pages,
            'fields' => $fields,
            'run_id' => $runId,
        ];
    }

    /**
     * Links and GET form actions that are worth visiting.
     *
     * @return array<int, string>
     */
    private function followableUrls(ParsedPage $parsed, string $pageUrl, string $base): array
    {
        $urls = array_column($parsed->links, 'url');

        foreach ($parsed->forms as $form) {
            if ($form['method'] !== 'GET' || $form['action'] === '') {
                continue;
            }
            $urls[] = Url::resolve($pageUrl, $form['action']);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @param array<int, string> $allow
     */
    private function mayVisit(string $base, string $url, array $allow): bool
    {
        if (!Url::isInside($base, $url)) {
            return false;
        }

        return Url::matchesAny(Url::pathOf($base, $url), $allow);
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

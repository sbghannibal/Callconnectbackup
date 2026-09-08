<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\DiscoveryService;

final class DiscoveryServiceTest extends DatabaseTestCase
{
    private const BASE_URL = 'https://portal.example';

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        $read = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/fixtures/pages/' . $file);

        $pages = [
            '/portal/users' => $read('users.html'),
            '/portal/users/A1' => $read('user_detail.html'),
            '/portal/users/A2' => $read('user_detail_a2.html'),
            '/admin/system' => $read('admin_system.html'),
        ];

        // The tab endpoints of the detail page serve the same document.
        foreach (['details', 'licenses', 'numbers', 'incoming'] as $tab) {
            $pages['/portal/users/A1?tab=' . $tab] = $pages['/portal/users/A1'];
        }

        return $pages;
    }

    private function config(array $overrides = []): Config
    {
        return new Config(
            dbHost: $this->config->dbHost,
            dbPort: $this->config->dbPort,
            dbName: $this->config->dbName,
            dbUser: $this->config->dbUser,
            dbPassword: $this->config->dbPassword,
            baseUrl: self::BASE_URL,
            username: 'tester',
            password: 'secret',
            discoverySeedPaths: $overrides['seeds'] ?? '/portal/users',
            discoveryAllowPatterns: $overrides['allow'] ?? '/portal/*',
            discoveryMaxDepth: $overrides['depth'] ?? 2,
            discoveryMaxPages: $overrides['maxPages'] ?? 50,
        );
    }

    private function discover(array $overrides = [], bool $loginSucceeds = true): array
    {
        $client = new FakeDiscoveryClient(self::BASE_URL, $this->pages(), $loginSucceeds);
        $result = (new DiscoveryService($this->repository, $client, $this->config($overrides)))->run();

        return [$result, $client];
    }

    public function testCrawlerFollowsInternalLinksAndStoresPages(): void
    {
        [$result] = $this->discover();

        $this->assertTrue($result['success'], $result['message']);

        $paths = array_column($this->repository->listDiscoveredPages(), 'path');
        sort($paths);
        $this->assertSame([
            '/portal/users',
            '/portal/users/A1',
            '/portal/users/A1?tab=details',
            '/portal/users/A1?tab=incoming',
            '/portal/users/A1?tab=licenses',
            '/portal/users/A1?tab=numbers',
            '/portal/users/A2',
        ], $paths);
        $this->assertSame(count($paths), $result['pages']);
    }

    public function testPagesOutsideTheAllowlistAreSkipped(): void
    {
        [, $client] = $this->discover();

        $this->assertNotContains('/admin/system', $client->requested);
        $this->assertNotContains('https://elsewhere.example.com/other', $client->requested);
    }

    public function testDepthLimitStopsTheCrawl(): void
    {
        [$result] = $this->discover(['depth' => 0]);

        $this->assertSame(1, $result['pages']);
        $this->assertSame(['/portal/users'], array_column($this->repository->listDiscoveredPages(), 'path'));
    }

    public function testPageLimitStopsTheCrawl(): void
    {
        [$result] = $this->discover(['maxPages' => 2]);

        $this->assertSame(2, $result['pages']);
    }

    public function testExtractedFieldsTabsAndRawHtmlAreStored(): void
    {
        $this->discover();

        $pages = $this->repository->listDiscoveredPages();
        $detail = $this->pageByPath($pages, '/portal/users/A1');

        $this->assertSame('User A1 - Reception', $detail['title']);
        $this->assertContains('Licenses', json_decode((string) $detail['tabs_json'], true));
        $this->assertStringContainsString('Do Not Disturb', (string) $detail['raw_html']);

        $fields = $this->repository->listDiscoveredFields((int) $detail['id']);
        $this->assertGreaterThan(0, (int) $detail['field_count']);

        $dnd = $this->fieldByLabel($fields, 'Do Not Disturb');
        $this->assertSame('form_field', $dnd['kind']);
        $this->assertSame('checkbox', $dnd['field_type']);
        $this->assertSame(1, (int) $dnd['selected']);

        $plan = $this->fieldByLabel($fields, 'Incoming calling plan');
        $this->assertSame('internal', $plan['value']);
        $this->assertContains(
            ['value' => 'internal', 'label' => 'Internal only'],
            json_decode((string) $plan['options_json'], true)
        );

        $email = $this->fieldByLabel($fields, 'E-mail address');
        $this->assertSame('anna.peeters@example.be', $email['value']);
        $this->assertSame('Details', $email['section']);
    }

    public function testUnreachablePagesAreStoredWithTheirStatusCode(): void
    {
        [$result] = $this->discover(['seeds' => "/portal/users\n/portal/missing"]);

        $this->assertTrue($result['success']);
        $missing = $this->pageByPath($this->repository->listDiscoveredPages(), '/portal/missing');
        $this->assertSame(404, (int) $missing['status_code']);
        $this->assertStringContainsString('could not be retrieved', $result['message']);
    }

    public function testEveryRunIsLoggedTogetherWithTheLoginAttempt(): void
    {
        [$result] = $this->discover();

        $runs = $this->repository->listDiscoveryRuns();
        $this->assertCount(1, $runs);
        $this->assertSame(1, (int) $runs[0]['success']);
        $this->assertSame($result['pages'], (int) $runs[0]['pages']);
        $this->assertNotNull($runs[0]['finished_at']);

        $login = $this->repository->listLoginLog();
        $this->assertCount(1, $login);
        $this->assertSame('tester', $login[0]['username']);
    }

    public function testFailedLoginStopsDiscoveryAndIsLogged(): void
    {
        [$result] = $this->discover([], false);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->repository->listDiscoveryRuns());
        $this->assertSame(0, (int) $this->repository->listLoginLog()[0]['success']);
    }

    public function testRerunningDiscoveryDoesNotDuplicateFields(): void
    {
        $this->discover();
        $first = $this->pageByPath($this->repository->listDiscoveredPages(), '/portal/users/A1');

        $this->discover();
        $second = $this->pageByPath($this->repository->listDiscoveredPages(), '/portal/users/A1');

        $this->assertSame((int) $first['field_count'], (int) $second['field_count']);
        $this->assertCount(2, $this->repository->listDiscoveryRuns());
    }

    private function pageByPath(array $pages, string $path): array
    {
        foreach ($pages as $page) {
            if ($page['path'] === $path) {
                return $page;
            }
        }

        $this->fail(sprintf('Page "%s" not found', $path));
    }

    private function fieldByLabel(array $fields, string $label): array
    {
        foreach ($fields as $field) {
            if ($field['label'] === $label) {
                return $field;
            }
        }

        $this->fail(sprintf('Field "%s" not found', $label));
    }
}

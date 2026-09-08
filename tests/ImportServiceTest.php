<?php

declare(strict_types=1);

namespace App\Tests;

use App\ImportService;
use App\Repository;

final class ImportServiceTest extends DatabaseTestCase
{
    private function records(): array
    {
        return [
            ['id' => 'A1', 'name' => 'Reception', 'destination' => '022001000', 'active' => true],
            ['id' => 'A2', 'name' => 'Support', 'destination' => '022001001', 'active' => false],
        ];
    }

    public function testImportStoresRecordsAndParameters(): void
    {
        $client = new FakeCallConnectClient(true, $this->records());
        $result = (new ImportService($this->repository, $client, $this->config))->run();

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']);

        $records = $this->repository->listRecords();
        $this->assertCount(2, $records);
        $this->assertSame('Reception', $records[0]['label']);

        $names = array_column($records[0]['parameters'], 'name');
        sort($names);
        $this->assertSame(['active', 'destination', 'name'], $names);

        $active = $this->parameter($records[0]['parameters'], 'active');
        $this->assertSame('1', $active['remote_value']);
    }

    public function testEveryLoginAttemptIsLogged(): void
    {
        $client = new FakeCallConnectClient(true, $this->records());
        (new ImportService($this->repository, $client, $this->config))->run();

        $log = $this->repository->listLoginLog();
        $this->assertCount(1, $log);
        $this->assertSame(1, (int) $log[0]['success']);
        $this->assertSame('tester', $log[0]['username']);
        $this->assertNotNull($log[0]['finished_at']);
    }

    public function testFailedLoginIsLoggedAndStopsTheImport(): void
    {
        $client = new FakeCallConnectClient(false, $this->records());
        $result = (new ImportService($this->repository, $client, $this->config))->run();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $this->repository->listRecords());

        $log = $this->repository->listLoginLog();
        $this->assertCount(1, $log);
        $this->assertSame(0, (int) $log[0]['success']);
        $this->assertSame(401, (int) $log[0]['status_code']);
        $this->assertStringContainsString('Invalid credentials', (string) $log[0]['message']);
    }

    public function testReimportKeepsLocalChangesButRefreshesRemoteValue(): void
    {
        $client = new FakeCallConnectClient(true, $this->records());
        (new ImportService($this->repository, $client, $this->config))->run();

        $records = $this->repository->listRecords();
        $parameter = $this->parameter($records[0]['parameters'], 'destination');
        $this->repository->updateParameterValue((int) $parameter['id'], '022009999');

        $client = new FakeCallConnectClient(true, [
            ['id' => 'A1', 'name' => 'Reception', 'destination' => '022001234', 'active' => true],
        ]);
        (new ImportService($this->repository, $client, $this->config))->run();

        $updated = $this->repository->getParameter((int) $parameter['id']);
        $this->assertSame('022009999', $updated['local_value']);
        $this->assertSame('022001234', $updated['remote_value']);
        $this->assertSame(Repository::STATUS_MODIFIED, $updated['status']);
    }

    private function parameter(array $parameters, string $name): array
    {
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name) {
                return $parameter;
            }
        }

        $this->fail(sprintf('Parameter "%s" not found', $name));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\ImportService;
use App\PushService;
use App\Repository;

final class PushServiceTest extends DatabaseTestCase
{
    private function seed(): array
    {
        $client = new FakeCallConnectClient(true, [
            ['id' => 'A1', 'name' => 'Reception', 'destination' => '022001000'],
        ]);
        (new ImportService($this->repository, $client, $this->config))->run();

        $records = $this->repository->listRecords();

        return $records[0]['parameters'];
    }

    private function parameterId(array $parameters, string $name): int
    {
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name) {
                return (int) $parameter['id'];
            }
        }

        $this->fail(sprintf('Parameter "%s" not found', $name));
    }

    public function testOnlySelectedAndChangedParametersArePushed(): void
    {
        $parameters = $this->seed();
        $destinationId = $this->parameterId($parameters, 'destination');
        $nameId = $this->parameterId($parameters, 'name');

        $this->repository->updateParameterValue($destinationId, '022007777');
        $this->repository->setParameterSelected($destinationId, true);
        // Changed but not marked, so it must stay behind.
        $this->repository->updateParameterValue($nameId, 'Onthaal');

        $client = new FakeCallConnectClient(true);
        $result = (new PushService($this->repository, $client, $this->config))->run();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['pushed']);
        $this->assertSame([['externalId' => 'A1', 'payload' => ['destination' => '022007777']]], $client->pushes);

        $pushed = $this->repository->getParameter($destinationId);
        $this->assertSame(Repository::STATUS_PUSHED, $pushed['status']);
        $this->assertSame('022007777', $pushed['remote_value']);
        $this->assertSame(0, (int) $pushed['selected']);

        $untouched = $this->repository->getParameter($nameId);
        $this->assertSame(Repository::STATUS_MODIFIED, $untouched['status']);
    }

    public function testPushIsLoggedTogetherWithTheLogin(): void
    {
        $parameters = $this->seed();
        $id = $this->parameterId($parameters, 'destination');
        $this->repository->updateParameterValue($id, '022007777');
        $this->repository->setParameterSelected($id, true);

        $client = new FakeCallConnectClient(true);
        (new PushService($this->repository, $client, $this->config))->run();

        $pushLog = $this->repository->listPushLog();
        $this->assertCount(1, $pushLog);
        $this->assertSame(1, (int) $pushLog[0]['success']);
        $this->assertSame('A1', $pushLog[0]['external_id']);

        // One login for the import plus one for the push.
        $this->assertCount(2, $this->repository->listLoginLog());
    }

    public function testFailedPushMarksParameterAsFailedAndKeepsItSelected(): void
    {
        $parameters = $this->seed();
        $id = $this->parameterId($parameters, 'destination');
        $this->repository->updateParameterValue($id, '022007777');
        $this->repository->setParameterSelected($id, true);

        $client = new FakeCallConnectClient(true, [], false);
        $result = (new PushService($this->repository, $client, $this->config))->run();

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['failed']);

        $parameter = $this->repository->getParameter($id);
        $this->assertSame(Repository::STATUS_FAILED, $parameter['status']);
        $this->assertSame(1, (int) $parameter['selected']);
        $this->assertSame('022001000', $parameter['remote_value']);
        $this->assertCount(1, $this->repository->listSelectedParameters());

        $pushLog = $this->repository->listPushLog();
        $this->assertSame(0, (int) $pushLog[0]['success']);
    }

    public function testFailedLoginBlocksThePushAndIsLogged(): void
    {
        $parameters = $this->seed();
        $id = $this->parameterId($parameters, 'destination');
        $this->repository->updateParameterValue($id, '022007777');
        $this->repository->setParameterSelected($id, true);

        $client = new FakeCallConnectClient(false);
        $result = (new PushService($this->repository, $client, $this->config))->run();

        $this->assertFalse($result['success']);
        $this->assertSame([], $client->pushes);
        $this->assertSame([], $this->repository->listPushLog());

        $log = $this->repository->listLoginLog();
        $this->assertSame(0, (int) $log[0]['success']);
    }

    public function testNothingSelectedDoesNotLogIn(): void
    {
        $this->seed();
        $client = new FakeCallConnectClient(true);
        $result = (new PushService($this->repository, $client, $this->config))->run();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $client->loginCalls);
        $this->assertSame(0, $result['pushed']);
    }
}

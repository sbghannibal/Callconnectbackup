<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\HttpCallConnectClient;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real cURL client against a local CallConnect look-alike.
 */
final class HttpCallConnectClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        @unlink(sys_get_temp_dir() . '/callconnect_mock_records.json');

        $port = 8000 + random_int(100, 900);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        $command = sprintf(
            'exec php -S 127.0.0.1:%d %s',
            $port,
            escapeshellarg(__DIR__ . '/fixtures/mock_server.php')
        );
        $pipes = [];
        self::$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(sys_get_temp_dir() . '/callconnect_mock_records.json');
    }

    private function client(): HttpCallConnectClient
    {
        return new HttpCallConnectClient(new Config(baseUrl: self::$baseUrl, timeout: 5));
    }

    public function testLoginFetchAndPush(): void
    {
        $client = $this->client();

        $login = $client->login('tester', 'secret');
        $this->assertTrue($login->success, $login->message);
        $this->assertSame(200, $login->statusCode);

        $records = $client->fetchRecords();
        $this->assertTrue($records->success);
        $this->assertCount(2, $records->data);
        $this->assertSame('A1', $records->data[0]['id']);

        $push = $client->pushRecord('A1', ['destination' => '022005555']);
        $this->assertTrue($push->success, $push->message);

        $refreshed = $client->fetchRecords();
        $this->assertSame('022005555', $refreshed->data[0]['destination']);
    }

    public function testInvalidCredentialsReturnAFailure(): void
    {
        $response = $this->client()->login('tester', 'wrong');

        $this->assertFalse($response->success);
        $this->assertSame(401, $response->statusCode);
        $this->assertSame('Invalid credentials', $response->message);
    }

    public function testRequestWithoutLoginIsRejected(): void
    {
        $response = $this->client()->fetchRecords();

        $this->assertFalse($response->success);
        $this->assertSame(401, $response->statusCode);
    }

    public function testFetchPageReturnsTheRawHtmlOfAPortalPage(): void
    {
        $client = $this->client();
        $client->login('tester', 'secret');

        $response = $client->fetchPage('/portal/users/A1');

        $this->assertTrue($response->success, $response->message);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('text/html', $response->contentType);
        $this->assertStringContainsString('Do Not Disturb', $response->body);
    }

    public function testFetchPageWithoutLoginIsRejected(): void
    {
        $response = $this->client()->fetchPage('/portal/users');

        $this->assertFalse($response->success);
        $this->assertSame(401, $response->statusCode);
    }
}

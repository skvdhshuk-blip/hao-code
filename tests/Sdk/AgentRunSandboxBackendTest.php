<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;
use HaoCode\Sdk\Sandbox\Backends\AgentRunSandboxBackend;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AgentRunSandboxBackendTest extends TestCase
{
    public function test_client_sends_api_key_and_parent_headers(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');
            return new MockResponse('{"content":"hello"}', ['http_code' => 200]);
        });

        $client = new AgentRunClient(
            accountId: '1234567890',
            sandboxId: 'sbx-1',
            apiKey: 'ak-template',
            httpClient: $http,
        );

        $this->assertSame('hello', $client->readFile('/tmp/a.txt'));
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('https://1234567890.agentrun-data.cn-hangzhou.aliyuncs.com/sandboxes/sbx-1/files?path=%2Ftmp%2Fa.txt', $requests[0]['url']);
        $headers = implode("\n", $requests[0]['options']['headers']);
        $this->assertStringContainsString('X-API-Key: ak-template', $headers);
        $this->assertStringContainsString('X-Acs-Parent-Id: 1234567890', $headers);
    }

    public function test_backend_maps_file_and_exec_operations(): void
    {
        $responses = [
            new MockResponse('{}', ['http_code' => 200]),
            new MockResponse('{"content":"alpha"}', ['http_code' => 200]),
            new MockResponse('{"result":{"stdout":"ok\n","stderr":"warn","exitCode":7}}', ['http_code' => 200]),
        ];
        $http = new MockHttpClient($responses);
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', apiKey: 'ak-template', httpClient: $http);
        $backend = new AgentRunSandboxBackend(SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1', apiKey: 'ak-template'), $client);

        $backend->writeFile('/tmp/a.txt', 'alpha');
        $this->assertSame('alpha', $backend->readFile('/tmp/a.txt'));
        $exec = $backend->exec('echo ok', '/tmp', 5000);

        $this->assertSame(7, $exec['exitCode']);
        $this->assertSame("ok\n", $exec['stdout']);
        $this->assertSame('warn', $exec['stderr']);
        $this->assertFalse($exec['outputLimited'] ?? true);
    }

    public function test_backend_caps_large_exec_output(): void
    {
        $responses = [
            new MockResponse('{"result":{"stdout":"'.str_repeat('x', 150000).'","stderr":"","exitCode":0}}', ['http_code' => 200]),
        ];
        $http = new MockHttpClient($responses);
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', apiKey: 'ak-template', httpClient: $http);
        $backend = new AgentRunSandboxBackend(SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1', apiKey: 'ak-template'), $client);

        $exec = $backend->exec('yes', '/tmp', 5000);

        $this->assertSame(1, $exec['exitCode']);
        $this->assertTrue($exec['outputLimited'] ?? false);
        $this->assertLessThanOrEqual(101000, strlen($exec['stdout']));
        $this->assertStringContainsString('stdout truncated', $exec['stdout']);
    }

    public function test_client_bounds_error_response_body(): void
    {
        $http = new MockHttpClient([
            new MockResponse(str_repeat('x', 128 * 1024), ['http_code' => 502]),
        ]);
        $client = new AgentRunClient(
            accountId: '1234567890',
            sandboxId: 'sbx-1',
            httpClient: $http,
        );

        try {
            $client->health();
            $this->fail('Expected AgentRun HTTP error.');
        } catch (\RuntimeException $exception) {
            $this->assertLessThanOrEqual(64 * 1024 + strlen('AgentRun HTTP 502: '), strlen($exception->getMessage()));
        }
    }
}

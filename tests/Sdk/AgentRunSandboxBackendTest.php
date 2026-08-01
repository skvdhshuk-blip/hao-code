<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;
use HaoCode\Sdk\Sandbox\Backends\AgentRunSandboxBackend;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Sdk\Sandbox\Tools\SandboxGlobTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxWriteTool;
use HaoCode\Tools\ToolUseContext;
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

    public function test_write_result_marks_agentrun_as_recheck_only(): void
    {
        $http = new MockHttpClient([
            new MockResponse('not found', ['http_code' => 404]),
            new MockResponse('not found', ['http_code' => 404]),
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $config = SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1');
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', httpClient: $http);
        $runtime = new SandboxRuntime($config, new AgentRunSandboxBackend($config, $client));

        $result = (new SandboxWriteTool($runtime))->call(
            ['file_path' => '/workspace/a.txt', 'content' => 'alpha'],
            new ToolUseContext('/workspace', 'agentrun-write-contract'),
        );

        $this->assertFalse($result->isError, $result->output);
        $this->assertSame('recheck_only', $result->metadata['writeSafety'] ?? null);
        $this->assertFalse($result->metadata['conditionalWrite'] ?? true);
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

    public function test_backend_aborts_while_streaming_a_remote_exec_response(): void
    {
        $response = new MockResponse([
            '{"result":{"stdout":"partial',
            '\\n","stderr":"","exitCode":0}}',
        ], ['http_code' => 200]);
        $http = new MockHttpClient([$response]);
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', httpClient: $http);
        $backend = new AgentRunSandboxBackend(
            SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1'),
            $client,
        );
        $checks = 0;

        $result = $backend->exec(
            'long-running',
            '/tmp',
            5000,
            static function () use (&$checks): bool {
                return ++$checks >= 2;
            },
        );

        $this->assertTrue($result['aborted']);
        $this->assertSame(130, $result['exitCode']);
        $this->assertFalse($result['timedOut']);
        $this->assertFalse($result['outputLimited']);
        $this->assertGreaterThanOrEqual(2, $checks);
    }

    public function test_search_prunes_and_reports_remote_search_bounds(): void
    {
        $commands = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$commands): MockResponse {
            $payload = is_array($options['json'] ?? null)
                ? $options['json']
                : (json_decode((string) ($options['body'] ?? ''), true) ?: []);
            $commands[] = $payload['command'] ?? '';

            return new MockResponse(json_encode([
                'result' => [
                    'stdout' => "/workspace/src/App.php\n".AgentRunSandboxBackend::class,
                    'stderr' => '',
                    'exitCode' => 0,
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $config = SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1');
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', httpClient: $http);
        $backend = new AgentRunSandboxBackend($config, $client);
        $runtime = new SandboxRuntime($config, $backend);

        $result = (new SandboxGlobTool($runtime))->call(
            ['pattern' => '**/*.php', 'path' => '/workspace'],
            new ToolUseContext('/workspace', 'agentrun-search-contract'),
        );

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('/workspace/src/App.php', $result->output);
        $this->assertSame('agentrun', $result->metadata['provider'] ?? null);
        $this->assertSame(100, $result->metadata['resultLimit'] ?? null);
        $this->assertFalse($result->metadata['searchLimited'] ?? true);
        $this->assertStringContainsString('-prune', $commands[0]);
        $this->assertStringContainsString('awk', $commands[0]);
    }

    public function test_glob_reports_result_limit_without_returning_extra_paths(): void
    {
        $paths = [];
        for ($index = 0; $index < 101; $index++) {
            $paths[] = "/workspace/file{$index}.php";
        }
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'result' => [
                    'stdout' => implode("\n", $paths)."\n__HAOCODE_SEARCH_RESULT_LIMIT__\n",
                    'stderr' => '',
                    'exitCode' => 3,
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $config = SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1');
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', httpClient: $http);
        $runtime = new SandboxRuntime($config, new AgentRunSandboxBackend($config, $client));

        $result = (new SandboxGlobTool($runtime))->call(
            ['pattern' => '**/*.php', 'path' => '/workspace'],
            new ToolUseContext('/workspace', 'agentrun-search-limit'),
        );

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('Found 100 file(s)', $result->output);
        $this->assertTrue($result->metadata['searchLimited'] ?? false);
        $this->assertTrue($result->metadata['resultLimited'] ?? false);
        $this->assertSame(100, $result->metadata['resultLimit'] ?? null);
        $this->assertStringNotContainsString('/workspace/file100.php', $result->output);
    }

    public function test_search_glob_filter_matches_relative_paths_not_only_basenames(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'result' => [
                    'stdout' => "/workspace/src/App.php:2:needle\n/workspace/src/sub/App.php:3:needle\n",
                    'stderr' => '',
                    'exitCode' => 0,
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $config = SandboxConfig::agentRun(accountId: '1234567890', sandboxId: 'sbx-1');
        $client = new AgentRunClient(accountId: '1234567890', sandboxId: 'sbx-1', httpClient: $http);
        $backend = new AgentRunSandboxBackend($config, $client);

        $matches = $backend->grep('needle', '/workspace', 'src/*.php');

        $this->assertSame([
            ['file' => '/workspace/src/App.php', 'line' => 2, 'text' => 'needle'],
        ], $matches);
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

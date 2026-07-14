<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpTransport;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

final class RuntimeIntegrationTest extends TestCase
{
    public function test_hao_code_server_and_client_share_stdio_framing(): void
    {
        $client = $this->clientForFixture('hao-code-mcp-stdio-server.php');

        try {
            $client->connect(3);

            $this->assertTrue($client->isConnected());
            $this->assertSame([], $client->listTools(false, 3));
        } finally {
            $client->close();
        }
    }

    public function test_stdio_server_does_not_inherit_unconfigured_secrets(): void
    {
        $previous = getenv('HAOCODE_MCP_SECRET_PROBE');
        putenv('HAOCODE_MCP_SECRET_PROBE=present');
        $client = $this->clientForFixture('mcp-jsonl-probe-server.php');

        try {
            $client->connect(3);

            $this->assertSame('not-inherited', $client->getServerInfo()['name'] ?? null);
        } finally {
            $client->close();
            $previous === false
                ? putenv('HAOCODE_MCP_SECRET_PROBE')
                : putenv('HAOCODE_MCP_SECRET_PROBE='.$previous);
        }
    }

    public function test_stdio_server_receives_explicitly_configured_environment(): void
    {
        $client = $this->clientForFixture(
            'mcp-jsonl-probe-server.php',
            ['HAOCODE_MCP_SECRET_PROBE' => 'explicit'],
        );

        try {
            $client->connect(3);

            $this->assertSame('inherited', $client->getServerInfo()['name'] ?? null);
        } finally {
            $client->close();
        }
    }

    public function test_stdio_server_starts_in_the_configured_working_directory(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => ['tests/fixtures/mcp-jsonl-probe-server.php'],
            'url' => null,
            'env' => [],
            'headers' => [],
            'cwd' => $projectRoot,
        ]);
        $client = new McpClient($transport, 'cwd-probe');

        try {
            $client->connect(3);

            $this->assertTrue($client->isConnected());
        } finally {
            $client->close();
        }
    }

    public function test_sdk_run_registers_and_calls_mcp_tools_from_configured_cwd(): void
    {
        $projectDir = sys_get_temp_dir().'/haocode-mcp-run-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/.haocode', 0755, true);
        file_put_contents($projectDir.'/.haocode/settings.json', json_encode([
            'mcp_servers' => [
                'context-probe' => [
                    'transport' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [dirname(__DIR__, 2).'/fixtures/mcp-jsonl-probe-server.php'],
                ],
                'unrelated-probe' => [
                    'transport' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [dirname(__DIR__, 2).'/fixtures/mcp-jsonl-probe-server.php'],
                    'env' => ['HAOCODE_MCP_STARTED_FILE' => $projectDir.'/unrelated.started'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = new HaoCodeConfig(
            apiKey: 'test-key',
            cwd: $projectDir,
            allowedTools: ['mcp__context_probe__echo_value'],
            ephemeral: true,
        );
        $run = SdkRunFactory::create(
            $config,
            SdkRuntime::app(AgentLoopFactory::class),
        );

        try {
            $registryProperty = new \ReflectionProperty($run->loop, 'toolRegistry');
            /** @var ToolRegistry $registry */
            $registry = $registryProperty->getValue($run->loop);
            $tool = $registry->getTool('mcp__context_probe__echo_value');

            $this->assertNotNull($tool);
            $this->assertFileDoesNotExist($projectDir.'/unrelated.started');
            $result = $tool->call(
                ['value' => 'works'],
                new ToolUseContext($projectDir, 'mcp-runtime-test'),
            );
            $this->assertFalse($result->isError);
            $this->assertSame('echo: works', $result->output);
        } finally {
            $run->close();
            @unlink($projectDir.'/.haocode/settings.json');
            @unlink($projectDir.'/unrelated.started');
            @rmdir($projectDir.'/.haocode');
            @rmdir($projectDir);
        }
    }

    public function test_sdk_run_fails_when_an_explicitly_allowed_mcp_server_cannot_initialize(): void
    {
        $projectDir = sys_get_temp_dir().'/haocode-mcp-failure-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/.haocode', 0755, true);
        file_put_contents($projectDir.'/.haocode/settings.json', json_encode([
            'mcp_servers' => [
                'broken-probe' => [
                    'transport' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [dirname(__DIR__, 2).'/fixtures/mcp-jsonl-probe-server.php'],
                    'env' => ['HAOCODE_MCP_PROTOCOL_VERSION' => 'unsupported-version'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(McpConnectionException::class);
            $this->expectExceptionMessage('Unsupported protocol version');

            SdkRunFactory::create(
                new HaoCodeConfig(
                    apiKey: 'test-key',
                    cwd: $projectDir,
                    allowedTools: ['mcp__broken_probe__echo_value'],
                    ephemeral: true,
                ),
                SdkRuntime::app(AgentLoopFactory::class),
            );
        } finally {
            @unlink($projectDir.'/.haocode/settings.json');
            @rmdir($projectDir.'/.haocode');
            @rmdir($projectDir);
        }
    }

    public function test_sdk_run_rejects_normalized_mcp_tool_name_collisions(): void
    {
        $projectDir = sys_get_temp_dir().'/haocode-mcp-collision-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/.haocode', 0755, true);
        $server = [
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__, 2).'/fixtures/mcp-jsonl-probe-server.php'],
        ];
        file_put_contents($projectDir.'/.haocode/settings.json', json_encode([
            'mcp_servers' => [
                'duplicate-server' => $server,
                'duplicate_server' => $server,
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(McpConnectionException::class);
            $this->expectExceptionMessage('name collision');

            SdkRunFactory::create(
                new HaoCodeConfig(
                    apiKey: 'test-key',
                    cwd: $projectDir,
                    allowedTools: ['mcp__duplicate_server__echo_value'],
                    ephemeral: true,
                ),
                SdkRuntime::app(AgentLoopFactory::class),
            );
        } finally {
            @unlink($projectDir.'/.haocode/settings.json');
            @rmdir($projectDir.'/.haocode');
            @rmdir($projectDir);
        }
    }

    public function test_sdk_run_rejects_an_explicit_mcp_tool_that_is_not_discovered(): void
    {
        $projectDir = sys_get_temp_dir().'/haocode-mcp-missing-tool-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/.haocode', 0755, true);
        file_put_contents($projectDir.'/.haocode/settings.json', json_encode([
            'mcp_servers' => [
                'context-probe' => [
                    'transport' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [dirname(__DIR__, 2).'/fixtures/mcp-jsonl-probe-server.php'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(McpConnectionException::class);
            $this->expectExceptionMessage('was not discovered');

            SdkRunFactory::create(
                new HaoCodeConfig(
                    apiKey: 'test-key',
                    cwd: $projectDir,
                    allowedTools: ['mcp__context_probe__missing_tool'],
                    ephemeral: true,
                ),
                SdkRuntime::app(AgentLoopFactory::class),
            );
        } finally {
            @unlink($projectDir.'/.haocode/settings.json');
            @rmdir($projectDir.'/.haocode');
            @rmdir($projectDir);
        }
    }

    public function test_sandbox_wildcard_does_not_implicitly_enable_host_mcp_servers(): void
    {
        $allowsMcpTools = new \ReflectionMethod(SdkRunFactory::class, 'allowsMcpTools');

        $this->assertFalse($allowsMcpTools->invoke(null, new HaoCodeConfig(
            allowedTools: ['*'],
            sandbox: SandboxConfig::local(),
        )));
        $this->assertTrue($allowsMcpTools->invoke(null, new HaoCodeConfig(
            allowedTools: ['mcp__context7__query_docs'],
            sandbox: SandboxConfig::local(),
        )));
    }

    /** @param array<string, string> $env */
    private function clientForFixture(string $fixture, array $env = []): McpClient
    {
        $transport = McpTransport::fromConfig([
            'transport' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__, 2).'/fixtures/'.$fixture],
            'url' => null,
            'env' => $env,
            'headers' => [],
        ]);

        return new McpClient($transport, 'probe');
    }
}

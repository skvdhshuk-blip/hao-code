<?php

namespace Tests\Sdk;

use HaoCode\Sdk\AgentRun\AgentRunRamSigner;
use HaoCode\Sdk\AgentRun\AgentRunSandboxClient;
use HaoCode\Sdk\AgentRun\AgentRunSandboxConfig;
use HaoCode\Sdk\AgentRun\AgentRunSandboxTools;
use HaoCode\Sdk\AgentRun\Tools\AgentRunReadTool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AgentRunSandboxTest extends TestCase
{
    public function test_ram_signer_generates_agentrun_headers(): void
    {
        $config = new AgentRunSandboxConfig(
            accountId: '1234567890',
            sandboxId: 'sbx-1',
            region: 'cn-hangzhou',
            accessKeyId: 'ak-test',
            accessKeySecret: 'secret-test',
            parentId: '1234567890',
        );

        $headers = (new AgentRunRamSigner())->sign(
            url: 'https://1234567890-ram.agentrun-data.cn-hangzhou.aliyuncs.com/sandboxes/sbx-1/files?path=%2Fhome%2Fuser%2Fa.txt',
            method: 'GET',
            config: $config,
            time: new \DateTimeImmutable('2026-05-09T00:00:00Z'),
        );

        $this->assertSame('2026-05-09T00:00:00Z', $headers['x-acs-date']);
        $this->assertSame('UNSIGNED-PAYLOAD', $headers['x-acs-content-sha256']);
        $this->assertStringStartsWith('AGENTRUN4-HMAC-SHA256 Credential=ak-test/20260509/cn-hangzhou/agentrun/aliyun_v4_request', $headers['Agentrun-Authorization']);
        $this->assertStringContainsString('SignedHeaders=host;x-acs-content-sha256;x-acs-date', $headers['Agentrun-Authorization']);
    }

    public function test_client_uses_ram_endpoint_and_parent_header(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('{"path":"/home/user/a.txt","size":2}', ['http_code' => 200]);
        });
        $client = new AgentRunSandboxClient(new AgentRunSandboxConfig(
            accountId: '1234567890',
            sandboxId: 'sbx-1',
            accessKeyId: 'ak-test',
            accessKeySecret: 'secret-test',
            parentId: '1234567890',
        ), $http);

        $client->writeFile('/home/user/a.txt', 'hi');

        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('https://1234567890-ram.agentrun-data.cn-hangzhou.aliyuncs.com/sandboxes/sbx-1/files', $requests[0]['url']);
        $headers = implode("\n", $requests[0]['options']['headers']);
        $this->assertStringContainsString('Agentrun-Authorization: AGENTRUN4-HMAC-SHA256', $headers);
        $this->assertStringContainsString('X-Acs-Parent-Id: 1234567890', $headers);
        $body = json_decode($requests[0]['options']['body'], true);
        $this->assertSame('/home/user/a.txt', $body['path']);
    }

    public function test_config_replaces_local_tools_when_sandbox_is_enabled(): void
    {
        $config = new HaoCodeConfig(sandbox: new AgentRunSandboxConfig(accountId: '123', sandboxId: 'sbx-1'));
        $filter = $config->toolFilter();

        $this->assertNotNull($filter);
        $this->assertTrue($filter('Read'));
        $this->assertTrue($filter('Bash'));
        $this->assertFalse($filter('Edit'));
        $this->assertFalse($filter('apply_patch'));
        $this->assertSame('/home/user', $config->effectiveWorkingDirectory());

        $toolNames = array_map(fn ($tool): string => $tool->name(), $config->toolsForAgent());
        $this->assertSame(['Read', 'Write', 'Glob', 'Grep', 'Bash'], $toolNames);
    }

    public function test_read_tool_reads_from_sandbox_path_not_local_path(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('{"content":"alpha\nbeta\n"}', ['http_code' => 200]);
        });
        $client = new AgentRunSandboxClient(new AgentRunSandboxConfig(accountId: '123', sandboxId: 'sbx-1'), $http);
        $tool = new AgentRunReadTool($client);
        $context = new ToolUseContext('/workspace', 'session-1');

        $input = $tool->backfillObservableInput(['file_path' => 'src/App.php'], $context);
        $result = $tool->call($input, $context);

        $this->assertFalse($result->isError);
        $this->assertSame('/workspace/src/App.php', $input['file_path']);
        $this->assertStringContainsString('AgentRun sandbox', $result->output);
        $this->assertStringContainsString('alpha', $result->output);
        $this->assertStringContainsString('path=%2Fworkspace%2Fsrc%2FApp.php', $requests[0]['url']);
    }

    public function test_sync_directory_copies_text_snapshot_to_sandbox(): void
    {
        $tmp = sys_get_temp_dir().'/haocode-agentrun-sync-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/src', 0777, true);
        file_put_contents($tmp.'/src/App.php', "<?php\n echo 'hi';\n");
        mkdir($tmp.'/.git');
        file_put_contents($tmp.'/.git/config', 'ignored');

        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $client = new AgentRunSandboxClient(new AgentRunSandboxConfig(accountId: '123', sandboxId: 'sbx-1'), $http);

        $summary = $client->syncDirectory($tmp, '/home/user/project');

        $this->assertSame(['files' => 1, 'skipped' => 1], $summary);
        $body = json_decode($requests[0]['options']['body'], true);
        $this->assertSame('/home/user/project/src/App.php', $body['path']);
        $this->assertStringContainsString("echo 'hi'", $body['content']);

        @unlink($tmp.'/.git/config');
        @rmdir($tmp.'/.git');
        @unlink($tmp.'/src/App.php');
        @rmdir($tmp.'/src');
        @rmdir($tmp);
    }
}

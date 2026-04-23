<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * Black-box integration tests for the MCP server via proc_open.
 * Each test spawns a fresh server process and exchanges JSON-RPC frames.
 */
class ServerIntegrationTest extends TestCase
{
    private string $binPath;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binPath = realpath(__DIR__.'/../../../bin/hao-code') ?: '';
        $this->tmpRoot = sys_get_temp_dir().'/mcp-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0755, true);

        // Create a safe readable file for read tests
        file_put_contents($this->tmpRoot.'/hello.txt', 'hello world');

        // Create a .env that must be blacklisted
        file_put_contents($this->tmpRoot.'/.env', 'SECRET=do-not-expose');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_initialize(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $resp = $this->sendFrame($server, $this->initMsg());
        $this->closeServer($server);

        $this->assertArrayHasKey('result', $resp, 'initialize must return result');
        $this->assertArrayHasKey('serverInfo', $resp['result']);
        $meta = $resp['result']['serverInfo']['meta'];
        $this->assertArrayHasKey('allowed_tools', $meta);
        $this->assertContains('Read', $meta['allowed_tools']);
        $this->assertNotContains('Bash', $meta['allowed_tools'], 'Bash must not be in strict preset');
    }

    public function test_tools_list(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/list'));
        $this->closeServer($server);

        $this->assertArrayHasKey('result', $resp);
        $names = array_column($resp['result']['tools'], 'name');
        $this->assertContains('Read', $names);
        $this->assertContains('Grep', $names);
        $this->assertContains('Glob', $names);
        $this->assertNotContains('Bash', $names, 'strict preset must not expose Bash');
    }

    public function test_tools_call_read(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Read',
            'arguments' => ['file_path' => $this->tmpRoot.'/hello.txt'],
        ]));
        $this->closeServer($server);

        $this->assertArrayHasKey('result', $resp, 'Read on allowed path must succeed');
        $this->assertFalse($resp['result']['isError'] ?? false, 'Read must not return isError');
        $text = $resp['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('hello world', $text);
    }

    /** path_A: Bash denied by default (not in strict preset) */
    public function test_bash_denied_by_default(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Bash',
            'arguments' => ['command' => 'echo hi'],
        ]));
        $this->closeServer($server);

        // Bash not registered, so tools/call returns isError with "access denied"
        $result = $resp['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false, 'Bash must be denied in strict preset');
        $text = $result['content'][0]['text'] ?? '';
        $this->assertStringContainsString('access denied', $text);
    }

    /** path_B: Read .env must return "access denied" (blacklist) */
    public function test_blacklist_rejects_env(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Read',
            'arguments' => ['file_path' => $this->tmpRoot.'/.env'],
        ]));
        $this->closeServer($server);

        $result = $resp['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false, '.env must be rejected');
        $text = $result['content'][0]['text'] ?? '';
        $this->assertStringContainsString('access denied', $text);
        $this->assertStringNotContainsString('SECRET', $text, 'Rejection must not expose content');
    }

    /** path_C: Write outside --root must be rejected */
    public function test_write_outside_root_rejected(): void
    {
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=laravel-dev']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Write',
            'arguments' => [
                'file_path' => '/tmp/escape-'.bin2hex(random_bytes(4)).'.txt',
                'content' => 'escaped',
            ],
        ]));
        $this->closeServer($server);

        $result = $resp['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false, 'Write outside root must be rejected');
    }

    /** path_D: symlink pointing outside root must not escape */
    public function test_symlink_not_escape(): void
    {
        $linkPath = $this->tmpRoot.'/escape-link';
        @symlink('/etc/passwd', $linkPath);

        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict']);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Read',
            'arguments' => ['file_path' => $linkPath],
        ]));
        $this->closeServer($server);

        $result = $resp['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false, 'Symlink escape must be rejected');
        $text = $result['content'][0]['text'] ?? '';
        $this->assertStringContainsString('access denied', $text);
    }

    /** path_E: dangerouslyDisableSandbox env var is stripped, Bash still denied */
    public function test_dangerous_sandbox_stripped(): void
    {
        // Even with dangerouslyDisableSandbox in env, strict preset still denies Bash
        $server = $this->startServer(['--root='.$this->tmpRoot, '--preset=strict'], [
            'DANGEROUSLY_DISABLE_SANDBOX' => '1',
        ]);
        $this->sendFrame($server, $this->initMsg());
        $resp = $this->sendFrame($server, $this->rpcMsg('tools/call', [
            'name' => 'Bash',
            'arguments' => ['command' => 'echo hi'],
        ]));
        $this->closeServer($server);

        $result = $resp['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false, 'Bash must still be denied even with DANGEROUSLY_DISABLE_SANDBOX');
    }

    /** path_F: bypass_permissions env var causes fail-fast (non-zero exit) */
    public function test_bypass_fails_fast(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge(getenv() ?: [], ['HAO_CODE_BYPASS_PERMISSIONS' => '1']);
        $process = proc_open(
            [$this->binPath, 'mcp-serve', '--root='.$this->tmpRoot],
            $descriptors,
            $pipes,
            null,
            $env,
        );
        $this->assertIsResource($process, 'Process must start');

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $this->assertNotEquals(0, $exitCode, 'bypass_permissions must cause non-zero exit');
        $this->assertStringContainsString('bypass_permissions', $stderr);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function startServer(array $args = [], array $extraEnv = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge(getenv() ?: [], $extraEnv);
        $cmd = array_merge([$this->binPath, 'mcp-serve'], $args);
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process, 'MCP server process must start');

        // Make stdout non-blocking for reads with timeout
        stream_set_blocking($pipes[1], false);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function sendFrame(array &$server, array $msg): array
    {
        $json = json_encode($msg);
        $frame = 'Content-Length: '.strlen($json)."\r\n\r\n".$json;
        fwrite($server['pipes'][0], $frame);

        return $this->readFrame($server['pipes'][1]);
    }

    private function readFrame($pipe): array
    {
        $deadline = microtime(true) + 10;
        $buffer = '';
        $contentLength = null;

        while (microtime(true) < $deadline) {
            $chunk = fread($pipe, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }

            // Parse headers
            if ($contentLength === null) {
                while (($pos = strpos($buffer, "\r\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    if ($line === '') {
                        break; // end of headers
                    }
                    if (stripos($line, 'Content-Length:') === 0) {
                        $contentLength = (int) trim(substr($line, 15));
                    }
                }
            }

            if ($contentLength !== null && strlen($buffer) >= $contentLength) {
                $body = substr($buffer, 0, $contentLength);
                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : [];
            }

            usleep(5000);
        }

        return [];
    }

    private function closeServer(array &$server): void
    {
        fclose($server['pipes'][0]);
        fclose($server['pipes'][1]);
        fclose($server['pipes'][2]);
        proc_close($server['process']);
    }

    private function initMsg(): array
    {
        return $this->rpcMsg('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0.0.1'],
        ]);
    }

    private function rpcMsg(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => rand(1, 9999),
            'method' => $method,
            'params' => $params,
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir.'/'.$file;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

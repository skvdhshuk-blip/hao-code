<?php

namespace Tests\Sdk;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\Backends\LocalSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\NativeSandboxBackend;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Sdk\Sandbox\Tools\SandboxGlobTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxGrepTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxReadTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxBashTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxWriteTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait SandboxTestTestSandboxSearchFailuresHaveToolSpecificPrefixesConcern
{

    public function test_sandbox_search_failures_have_tool_specific_prefixes(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-search-errors');

        try {
            $grep = (new SandboxGrepTool($runtime))->call(
                ['pattern' => '['],
                $context,
            );
            $this->assertTrue($grep->isError);
            $this->assertStringStartsWith('Grep search failed: ', $grep->output);

            $glob = (new SandboxGlobTool($runtime))->call(
                ['pattern' => str_repeat('a', 513)],
                $context,
            );
            $this->assertTrue($glob->isError);
            $this->assertStringStartsWith('Glob search failed: ', $glob->output);
        } finally {
            $runtime->close();
        }
    }

    public function test_sandbox_search_tools_report_limits_and_abort_cleanly(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-search-tools');
        $aborted = new ToolUseContext(
            '/workspace',
            'sandbox-search-tools-aborted',
            shouldAbort: static fn (): bool => true,
        );

        try {
            $runtime->backend->writeFile('/workspace/a.txt', "needle\n");

            $grep = new SandboxGrepTool($runtime);
            $zero = $grep->call(['pattern' => 'needle', 'head_limit' => 0], $context);
            $this->assertFalse($zero->isError, $zero->output);
            $this->assertSame('No matches found for pattern: needle', $zero->output);

            $glob = new SandboxGlobTool($runtime);
            $tooBroad = $glob->call(['pattern' => str_repeat('{a,b}', 9).'*.txt'], $context);
            $this->assertTrue($tooBroad->isError);
            $this->assertStringContainsString('brace expansion', $tooBroad->output);

            $this->assertSame(ToolOutcome::Aborted, $glob->call(['pattern' => '**/*.txt'], $aborted)->outcome());
            $this->assertSame(ToolOutcome::Aborted, $grep->call(['pattern' => 'needle'], $aborted)->outcome());
        } finally {
            $runtime->close();
        }
    }

    public function test_sandbox_bash_strips_custom_policy_env_deny(): void
    {
        $project = $this->tmpDir('haocode-sandbox-policy-');
        mkdir($project.'/.haocode', 0777, true);
        $policy = $project.'/policy.yml';
        file_put_contents($policy, <<<'YAML'
rules:
  - name: sandbox-env
    tool: Bash
    cmd: env
    allow_auto: true
    env_deny:
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT
      - HAOCODE_CUSTOM_DENY
YAML);
        file_put_contents($project.'/.haocode/settings.json', json_encode([
            'permissions' => ['policy_files' => [$policy]],
        ], JSON_THROW_ON_ERROR));
        $runtime = SandboxManager::create(SandboxConfig::local(mode: 'full'));
        $context = new ToolUseContext(
            '/workspace',
            'sandbox-policy-env',
            runContext: AgentRunContextFactory::make(new HaoCodeConfig(cwd: $project)),
        );
        putenv('HAOCODE_CUSTOM_DENY=must-not-leak');

        try {
            $result = (new SandboxBashTool($runtime))->call(['command' => 'env'], $context);
            $this->assertFalse($result->isError, $result->output);
            $this->assertStringNotContainsString('HAOCODE_CUSTOM_DENY=must-not-leak', $result->output);
        } finally {
            putenv('HAOCODE_CUSTOM_DENY');
            $runtime->close();
            $this->removeDir($project);
        }
    }

    public function test_local_sandbox_detach_lease_survives_close_and_reattach(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local(cleanup: 'always'));
        $runtime->backend->writeFile('/workspace/keep.txt', 'durable-hitl');
        $lease = $runtime->exportLease();
        $this->assertIsArray($lease);
        $this->assertArrayHasKey('root', $lease);
        $root = (string) $lease['root'];
        $this->assertDirectoryExists($root);

        $runtime->detach();
        $runtime->close();
        $this->assertDirectoryExists($root);
        $this->assertFileExists($root.'/workspace/keep.txt');

        $reattach = SandboxManager::create(
            \HaoCode\Sdk\Sandbox\SandboxRuntime::configFromLease($lease, SandboxConfig::local(cleanup: 'always')),
        );
        $this->assertSame('durable-hitl', $reattach->backend->readFile('/workspace/keep.txt'));
        $reattach->close();
        // Original owned the temp root with cleanup always → reattach owns and cleans.
        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_native_sandbox_executes_in_workspace_and_blocks_host_writes(): void
    {
        if (! $this->nativeSandboxAvailable()) {
            $this->markTestSkipped('No native sandbox engine is installed on this host.');
        }

        $outside = $this->tmpDir('haocode-outside-').'/marker.txt';
        file_put_contents($outside, 'safe');
        $runtime = SandboxManager::create(SandboxConfig::native());
        $runtime->backend->writeFile('/workspace/input.txt', 'inside');

        $inside = $runtime->backend->exec('cat input.txt && printf created > output.txt', '/workspace', 5000);
        $this->assertSame(0, $inside['exitCode'], $inside['stderr']);
        $this->assertSame('inside', $inside['stdout']);
        $this->assertSame('created', $runtime->backend->readFile('/workspace/output.txt'));

        $escape = $runtime->backend->exec('printf compromised > '.escapeshellarg($outside), '/workspace', 5000);
        $this->assertNotSame(0, $escape['exitCode']);
        $this->assertSame('safe', file_get_contents($outside));
        $this->assertMatchesRegularExpression('/^(seatbelt|bubblewrap):/', $runtime->backend->rootLabel());

        $runtime->close();
        $this->removeDir(dirname($outside));
    }

    public function test_native_sandbox_rejects_invalid_options_and_missing_engines(): void
    {
        try {
            SandboxConfig::native(network: 'sometimes');
            $this->fail('An unknown network policy should fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('network policy', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        SandboxConfig::native(root: DIRECTORY_SEPARATOR);
    }

    public function test_local_file_api_rejects_symbolic_link_escapes(): void
    {
        $outsideDir = $this->tmpDir('haocode-symlink-outside-');
        $outside = $outsideDir.'/secret.txt';
        file_put_contents($outside, 'secret');
        $runtime = SandboxManager::create(SandboxConfig::local(cleanup: 'always'));
        $link = $runtime->backend->rootLabel().'/workspace/link.txt';
        if (! @symlink($outside, $link)) {
            $runtime->close();
            $this->removeDir($outsideDir);
            $this->markTestSkipped('Symbolic links are unavailable on this host.');
        }

        try {
            $runtime->backend->readFile('/workspace/link.txt');
            $this->fail('Reading through an escaping symbolic link should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        try {
            $runtime->backend->writeFile('/workspace/link.txt', 'compromised');
            $this->fail('Writing through an escaping symbolic link should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }

        $this->assertSame([], $runtime->backend->grep('secret', '/workspace'));
        $this->assertSame([], $runtime->backend->glob('**/*.txt', '/workspace'));
        $this->assertSame('secret', file_get_contents($outside));
        $runtime->close();
        $this->removeDir($outsideDir);
    }

    public function test_native_sandbox_never_falls_back_to_unsandboxed_execution(): void
    {
        $this->expectException(\RuntimeException::class);
        SandboxManager::create(SandboxConfig::native(engine: 'not-a-real-engine'));
    }

    public function test_native_sandbox_caps_captured_command_output(): void
    {
        if (! $this->nativeSandboxAvailable()) {
            $this->markTestSkipped('No native sandbox engine is installed on this host.');
        }

        $runtime = SandboxManager::create(SandboxConfig::native());
        $result = $runtime->backend->exec('yes x | head -c 5000000', '/workspace', 10000);

        $this->assertSame(1, $result['exitCode'], $result['stderr']);
        $this->assertTrue($result['outputLimited'] ?? false);
        $this->assertLessThan(101_000, strlen($result['stdout']));
        $this->assertStringContainsString('[stdout truncated at 100000 bytes]', $result['stdout']);
        $runtime->close();
    }

    public function test_config_filters_host_only_tools_when_sandbox_enabled(): void
    {
        $config = new HaoCodeConfig(
            allowedTools: ['*'],
            sandbox: SandboxConfig::local(),
        );
        $filter = $config->toolFilter();

        $this->assertNotNull($filter);
        $this->assertTrue($filter('Read'));
        $this->assertTrue($filter('Write'));
        $this->assertTrue($filter('Grep'));
        $this->assertFalse($filter('Bash'));
        $this->assertFalse($filter('Edit'));
        $this->assertFalse($filter('apply_patch'));
        $this->assertSame('/workspace', $config->effectiveWorkingDirectory());

        $full = new HaoCodeConfig(
            allowedTools: ['*'],
            sandbox: SandboxConfig::local(mode: 'full'),
        );
        $this->assertTrue(($full->toolFilter())('Bash'));
    }

    public function test_sandbox_manager_rejects_unknown_mode_before_backend_creation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sandbox mode');

        SandboxManager::create(new SandboxConfig(mode: 'unsafe'));
    }

    private function tmpDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function nativeSandboxAvailable(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return is_executable('/usr/bin/sandbox-exec');
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $path) {
            if (is_executable(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'bwrap')) {
                return true;
            }
        }

        return false;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}

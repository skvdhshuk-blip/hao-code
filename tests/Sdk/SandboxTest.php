<?php

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Sdk\Sandbox\Tools\SandboxReadTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxWriteTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SandboxTest extends TestCase
{
    public function test_local_sandbox_syncs_cwd_without_writing_host_files(): void
    {
        $cwd = $this->tmpDir('haocode-host-');
        file_put_contents($cwd.'/input.txt', "alpha\nbeta\n");

        $runtime = SandboxManager::create(SandboxConfig::local(sync: 'upload-cwd'), $cwd);
        $this->assertStringContainsString('alpha', $runtime->backend->readFile('/workspace/input.txt'));

        $runtime->backend->writeFile('/workspace/output.txt', 'sandbox only');
        $this->assertFileDoesNotExist($cwd.'/output.txt');

        $runtime->close();
        $this->removeDir($cwd);
    }

    public function test_sandbox_tools_resolve_relative_paths_inside_remote_cwd(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'test-session');

        $write = new SandboxWriteTool($runtime);
        $writeInput = $write->backfillObservableInput(['file_path' => 'notes/a.txt', 'content' => "hello\nworld"], $context);
        $writeResult = $write->call($writeInput, $context);

        $this->assertFalse($writeResult->isError, $writeResult->output);
        $this->assertSame('/workspace/notes/a.txt', $writeInput['file_path']);

        $read = new SandboxReadTool($runtime);
        $readInput = $read->backfillObservableInput(['file_path' => 'notes/a.txt'], $context);
        $readResult = $read->call($readInput, $context);

        $this->assertFalse($readResult->isError, $readResult->output);
        $this->assertStringContainsString('hello', $readResult->output);
        $this->assertStringContainsString('sandbox', $readResult->output);
    }

    public function test_local_sandbox_glob_grep_and_exec(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local(mode: 'full'));
        $runtime->backend->writeFile('/workspace/src/App.php', "<?php\necho 'needle';\n");

        $this->assertSame(['/workspace/src/App.php'], $runtime->backend->glob('**/*.php', '/workspace'));

        $matches = $runtime->backend->grep('needle', '/workspace', '**/*.php');
        $this->assertSame('/workspace/src/App.php', $matches[0]['file']);
        $this->assertSame(2, $matches[0]['line']);

        $exec = $runtime->backend->exec('pwd && ls src', '/workspace', 5000);
        $this->assertSame(0, $exec['exitCode']);
        $this->assertStringContainsString('App.php', $exec['stdout']);
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

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertLessThan(4_200_000, strlen($result['stdout']));
        $this->assertStringContainsString('[stdout truncated at 4194304 bytes]', $result['stdout']);
        $runtime->close();
    }

    public function test_config_filters_host_only_tools_when_sandbox_enabled(): void
    {
        $config = new HaoCodeConfig(sandbox: SandboxConfig::local());
        $filter = $config->toolFilter();

        $this->assertNotNull($filter);
        $this->assertTrue($filter('Read'));
        $this->assertTrue($filter('Write'));
        $this->assertTrue($filter('Grep'));
        $this->assertFalse($filter('Bash'));
        $this->assertFalse($filter('Edit'));
        $this->assertFalse($filter('apply_patch'));
        $this->assertSame('/workspace', $config->effectiveWorkingDirectory());

        $full = new HaoCodeConfig(sandbox: SandboxConfig::local(mode: 'full'));
        $this->assertTrue(($full->toolFilter())('Bash'));
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

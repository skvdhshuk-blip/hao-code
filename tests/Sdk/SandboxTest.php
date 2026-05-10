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

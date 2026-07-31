<?php

namespace Tests\Sdk;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Sdk\Sandbox\Tools\SandboxGlobTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxGrepTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxReadTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxBashTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxWriteTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SandboxTest extends TestCase
{
    public function test_generated_local_sandbox_root_is_private(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $runtime = SandboxManager::create(SandboxConfig::local());
        try {
            clearstatcache(true, $runtime->backend->rootLabel());
            $this->assertSame(
                0700,
                fileperms($runtime->backend->rootLabel()) & 0777,
            );
        } finally {
            $runtime->close();
        }
    }

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

    public function test_upload_cwd_does_not_follow_symlinks_to_host_files(): void
    {
        // Regression for chatgpt 3rd review #4: a symlink inside the project
        // directory that points at a file outside the project root must not
        // have its target copied into the sandbox. PHP's SplFileInfo reports
        // such a link as isFile()=true AND isLink()=true, so the old isFile()
        // gate happily read the target's contents.
        $outsideDir = $this->tmpDir('haocode-symlink-target-');
        $secretFile = $outsideDir.'/id_rsa';
        file_put_contents($secretFile, 'SECRET-HOST-KEY-CONTENT');

        $cwd = $this->tmpDir('haocode-symlink-host-');
        $regularFile = $cwd.'/regular.txt';
        file_put_contents($regularFile, 'public project content');

        // project/leak.txt -> outsideDir/id_rsa  (malicious symlink)
        $leakLink = $cwd.'/leak.txt';
        if (! @symlink($secretFile, $leakLink)) {
            $this->removeDir($cwd);
            $this->removeDir($outsideDir);
            $this->markTestSkipped('Symbolic links are unavailable on this host.');
        }

        try {
            $runtime = SandboxManager::create(SandboxConfig::local(sync: 'upload-cwd'), $cwd);

            // The regular file must have been synced.
            $this->assertSame('public project content', $runtime->backend->readFile('/workspace/regular.txt'));

            // The symlink must NOT have been followed — readFile must fail
            // (file does not exist in sandbox), and even if it did, the
            // secret content must not be readable.
            try {
                $leaked = $runtime->backend->readFile('/workspace/leak.txt');
                $this->assertStringNotContainsString('SECRET-HOST-KEY-CONTENT', $leaked, 'symlink target must not be copied into the sandbox');
            } catch (\RuntimeException $e) {
                // Expected: leak.txt was not synced.
                $this->addToAssertionCount(1);
            }

            $runtime->close();
        } finally {
            $this->removeDir($cwd);
            $this->removeDir($outsideDir);
        }
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

    public function test_sandbox_write_rejects_stale_read_revision(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-stale-write');
        $read = new SandboxReadTool($runtime);
        $write = new SandboxWriteTool($runtime);
        $path = '/workspace/stale.txt';

        try {
            $runtime->backend->writeFile($path, 'original');
            $readResult = $read->call(['file_path' => $path], $context);
            $this->assertFalse($readResult->isError, $readResult->output);
            $this->assertFalse($context->getFileRevision($path)?->local ?? true);

            $runtime->backend->writeFile($path, 'external change');
            $writeResult = $write->call([
                'file_path' => $path,
                'content' => 'stale overwrite',
            ], $context);

            $this->assertTrue($writeResult->isError);
            $this->assertStringContainsString('changed since it was read', $writeResult->output);
            $this->assertSame('external change', $runtime->backend->readFile($path));
        } finally {
            $runtime->close();
        }
    }

    public function test_sandbox_read_rejects_images_and_pdfs_without_recording_receipts(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-binary-read');
        $read = new SandboxReadTool($runtime);
        $imagePath = '/workspace/pixel.bin';
        $pdfPath = '/workspace/document.bin';

        try {
            $runtime->backend->writeFile($imagePath, "\x89PNG\r\n\x1A\nbinary");
            $imageResult = $read->call(['file_path' => $imagePath], $context);
            $this->assertTrue($imageResult->isError);
            $this->assertStringContainsString('image content blocks', $imageResult->output);
            $this->assertNull($context->getFileRevision($imagePath));

            $runtime->backend->writeFile($pdfPath, "%PDF-1.4\nbinary");
            $pdfResult = $read->call(['file_path' => $pdfPath], $context);
            $this->assertTrue($pdfResult->isError);
            $this->assertStringContainsString('PDF text cannot be extracted', $pdfResult->output);
            $this->assertNull($context->getFileRevision($pdfPath));
        } finally {
            $runtime->close();
        }
    }

    public function test_local_sandbox_read_streams_line_windows_without_caching_full_content(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-large-read');
        $read = new SandboxReadTool($runtime);
        $path = '/workspace/large.txt';

        try {
            $runtime->backend->writeFile($path, implode("\n", array_map(
                static fn (int $i): string => 'line-'.$i,
                range(1, 3000),
            )));

            $result = $read->call(['file_path' => $path, 'offset' => 100, 'limit' => 2], $context);

            $this->assertFalse($result->isError, $result->output);
            $this->assertStringContainsString('File: /workspace/large.txt (3000 lines total, sandbox)', $result->output);
            $this->assertStringContainsString("   100\tline-100", $result->output);
            $this->assertStringContainsString("   101\tline-101", $result->output);
            $this->assertFalse($context->getFileRevision($path)?->complete ?? true);
            $this->assertNull($context->getFileState($path));
        } finally {
            $runtime->close();
        }
    }

    public function test_local_sandbox_complete_streamed_read_still_authorizes_write(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-complete-streamed-read');
        $read = new SandboxReadTool($runtime);
        $write = new SandboxWriteTool($runtime);
        $path = '/workspace/full.txt';

        try {
            $runtime->backend->writeFile($path, "one\ntwo\nthree");
            $readResult = $read->call(['file_path' => $path, 'limit' => 10], $context);
            $this->assertFalse($readResult->isError, $readResult->output);
            $this->assertTrue($context->getFileRevision($path)?->complete ?? false);
            $this->assertNull($context->getFileState($path));

            $writeResult = $write->call(['file_path' => $path, 'content' => "updated\ncontent"], $context);
            $this->assertFalse($writeResult->isError, $writeResult->output);
            $this->assertSame("updated\ncontent", $runtime->backend->readFile($path));
        } finally {
            $runtime->close();
        }
    }

    public function test_local_sandbox_read_rejects_extreme_lines_and_reports_abort(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-long-line-read');
        $aborted = new ToolUseContext(
            '/workspace',
            'sandbox-read-aborted',
            shouldAbort: static fn (): bool => true,
        );
        $read = new SandboxReadTool($runtime);

        try {
            $runtime->backend->writeFile('/workspace/long.txt', str_repeat('x', 1_000_001));
            $tooLong = $read->call(['file_path' => '/workspace/long.txt'], $context);
            $this->assertTrue($tooLong->isError);
            $this->assertStringContainsString('Line exceeds', $tooLong->output);
            $this->assertNull($context->getFileRevision('/workspace/long.txt'));

            $runtime->backend->writeFile('/workspace/a.txt', "hello\n");
            $this->assertSame(ToolOutcome::Aborted, $read->call(['file_path' => '/workspace/a.txt'], $aborted)->outcome());
        } finally {
            $runtime->close();
        }
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

    public function test_local_sandbox_search_prunes_ignored_directories_before_recursing(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission coverage.');
        }

        $runtime = SandboxManager::create(SandboxConfig::local());
        $runtime->backend->writeFile('/workspace/src/keep.php', "<?php\nneedle\n");
        $locked = $runtime->backend->rootLabel().'/workspace/vendor/locked';
        mkdir($runtime->backend->rootLabel().'/workspace/vendor', 0755, true);
        mkdir($locked, 0000);

        try {
            $this->assertSame(['/workspace/src/keep.php'], $runtime->backend->glob('**/*.php', '/workspace'));

            $matches = $runtime->backend->grep('needle', '/workspace', '**/*.php');
            $this->assertCount(1, $matches);
            $this->assertSame('/workspace/src/keep.php', $matches[0]['file']);
        } finally {
            @chmod($locked, 0700);
            $runtime->close();
        }
    }

    public function test_local_sandbox_glob_retains_only_bounded_top_results(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        try {
            for ($i = 0; $i < 150; $i++) {
                $runtime->backend->writeFile('/workspace/files/file-'.$i.'.txt', 'x');
            }

            $matches = $runtime->backend->glob('**/*.txt', '/workspace');

            $this->assertCount(100, $matches);
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

        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertLessThan(4_200_000, strlen($result['stdout']));
        $this->assertStringContainsString('[stdout truncated at 4194304 bytes]', $result['stdout']);
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

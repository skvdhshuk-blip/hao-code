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

trait SandboxTestTestGeneratedLocalSandboxRootIsPrivateConcern
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

    public function test_local_sandbox_read_enforces_shared_bounds_and_reports_abort(): void
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

            $runtime->backend->writeFile(
                '/workspace/wide.txt',
                str_repeat(str_repeat('x', 2_000)."\n", 600),
            );
            $tooWide = $read->call(['file_path' => '/workspace/wide.txt'], $context);
            $this->assertTrue($tooWide->isError);
            $this->assertStringContainsString('Read output exceeds', $tooWide->output);
            $this->assertNull($context->getFileRevision('/workspace/wide.txt'));

            $runtime->backend->writeFile('/workspace/a.txt', "hello\n");
            $this->assertSame(ToolOutcome::Aborted, $read->call(['file_path' => '/workspace/a.txt'], $aborted)->outcome());
        } finally {
            $runtime->close();
        }
    }

    public function test_remote_sandbox_read_uses_shared_line_windows_and_does_not_record_late_aborts(): void
    {
        $backend = $this->createMock(SandboxBackendInterface::class);
        $backend->method('readFile')->willReturn("one\r\ntwo\nthree\r");
        $runtime = new SandboxRuntime(new SandboxConfig(provider: 'fixture'), $backend);
        $read = new SandboxReadTool($runtime);

        $context = new ToolUseContext('/workspace', 'remote-line-window');
        $result = $read->call([
            'file_path' => '/workspace/input.txt',
            'offset' => 2,
            'limit' => 1,
        ], $context);

        $this->assertFalse($result->isError, $result->output);
        $this->assertStringContainsString('(3 lines total, sandbox)', $result->output);
        $this->assertStringContainsString("     2\ttwo", $result->output);
        $this->assertFalse($context->getFileRevision('/workspace/input.txt')?->complete ?? true);

        $abortChecks = 0;
        $aborted = new ToolUseContext(
            '/workspace',
            'remote-late-abort',
            shouldAbort: static function () use (&$abortChecks): bool {
                $abortChecks++;

                return $abortChecks >= 4;
            },
        );
        $abortResult = $read->call(['file_path' => '/workspace/input.txt'], $aborted);

        $this->assertSame(ToolOutcome::Aborted, $abortResult->outcome());
        $this->assertNull($aborted->getFileRevision('/workspace/input.txt'));
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

    public function test_local_sandbox_exec_caps_output_and_terminates_promptly(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local(mode: 'full'));
        $marker = tempnam(sys_get_temp_dir(), 'haocode_sandbox_exec_limit_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        try {
            $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
                'echo str_repeat("x", 200000); flush(); usleep(1000000); file_put_contents('.var_export($marker, true).', "leaked");',
            );

            $result = $runtime->backend->exec($command, '/workspace', 5000);

            $this->assertSame(1, $result['exitCode']);
            $this->assertTrue($result['outputLimited'] ?? false);
            $this->assertFalse($result['timedOut']);
            $this->assertLessThanOrEqual(101_000, strlen($result['stdout']));
            $this->assertStringContainsString('stdout truncated', $result['stdout']);

            usleep(1_100_000);
            $this->assertFileDoesNotExist($marker, 'Output limit must terminate before delayed side effects run');
        } finally {
            @unlink($marker);
            $runtime->close();
        }
    }

    public function test_sandbox_bash_reports_output_limit_metadata(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local(mode: 'full'));
        $context = new ToolUseContext('/workspace', 'sandbox-bash-output-limit');
        $bash = new SandboxBashTool($runtime);

        try {
            $result = $bash->call([
                'command' => escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo str_repeat("x", 200000);'),
                'timeout' => 5000,
            ], $context);

            $this->assertTrue($result->isError, $result->output);
            $this->assertSame(1, $result->metadata['exitCode'] ?? null);
            $this->assertTrue($result->metadata['outputLimited'] ?? false);
            $this->assertStringContainsString('capture limit', $result->output);
        } finally {
            $runtime->close();
        }
    }

    public function test_native_sandbox_runner_caps_output_and_terminates_promptly(): void
    {
        $config = SandboxConfig::local(mode: 'full');
        $filesystem = new LocalSandboxBackend($config);
        $backend = (new \ReflectionClass(NativeSandboxBackend::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionClass($backend);
        $reflection->getProperty('config')->setValue($backend, $config);
        $reflection->getProperty('filesystem')->setValue($backend, $filesystem);

        $marker = tempnam(sys_get_temp_dir(), 'haocode_native_exec_limit_');
        $this->assertNotFalse($marker);
        @unlink($marker);

        try {
            $run = $reflection->getMethod('run');
            $run->setAccessible(true);
            $result = $run->invoke(
                $backend,
                [
                    PHP_BINARY,
                    '-r',
                    'echo str_repeat("x", 5 * 1024 * 1024); flush(); usleep(1000000); file_put_contents('.var_export($marker, true).', "leaked");',
                ],
                $filesystem->rootLabel().'/workspace',
                5000,
                null,
            );

            $this->assertSame(1, $result['exitCode']);
            $this->assertTrue($result['outputLimited'] ?? false);
            $this->assertFalse($result['timedOut']);
            $this->assertStringContainsString('stdout truncated', $result['stdout']);

            usleep(1_100_000);
            $this->assertFileDoesNotExist($marker, 'Native output limit must terminate before delayed side effects run');
        } finally {
            @unlink($marker);
            $filesystem->close();
        }
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

    public function test_sandbox_glob_uses_host_heading_and_marks_bounded_results(): void
    {
        $runtime = SandboxManager::create(SandboxConfig::local());
        $context = new ToolUseContext('/workspace', 'sandbox-glob-shape');

        try {
            for ($i = 0; $i < 101; $i++) {
                $runtime->backend->writeFile('/workspace/files/file-'.$i.'.txt', 'x');
            }

            $result = (new SandboxGlobTool($runtime))->call(
                ['pattern' => '**/*.txt', 'path' => '/workspace'],
                $context,
            );

            $this->assertFalse($result->isError, $result->output);
            $this->assertStringContainsString("Found 100 file(s) matching '**/*.txt' (showing first 100):", $result->output);
            $this->assertStringContainsString('Results are bounded. Narrow your pattern to see more.', $result->output);
            $this->assertStringNotContainsString(' in sandbox:', $result->output);
        } finally {
            $runtime->close();
        }
    }
}

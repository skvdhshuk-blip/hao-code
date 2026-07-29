<?php

namespace Tests\Unit;

use HaoCode\Services\Settings\SettingsFileStore;
use PHPUnit\Framework\TestCase;

class SettingsFileStoreTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/haocode_settings_store_'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
        $this->path = $this->directory.'/settings.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function test_invalid_json_is_rejected_without_overwriting_original_bytes(): void
    {
        $invalid = '{"provider":';
        file_put_contents($this->path, $invalid);
        $store = new SettingsFileStore($this->directory);

        try {
            $store->update($this->path, function (array &$settings): void {
                $settings['model'] = 'must-not-be-written';
            });
            $this->fail('Expected invalid settings JSON to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid JSON in settings file', $e->getMessage());
        }

        $this->assertSame($invalid, file_get_contents($this->path));
    }

    public function test_atomic_update_secures_settings_file(): void
    {
        $store = new SettingsFileStore($this->directory);

        $store->update($this->path, function (array &$settings): void {
            $settings['model'] = 'test-model';
        });

        $this->assertSame(['model' => 'test-model'], $store->read($this->path));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($this->path) & 0777);
        }
    }

    public function test_existing_settings_remain_readable_when_directory_is_read_only(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX directory permissions are required for this coverage.');
        }

        file_put_contents($this->path, json_encode(['model' => 'read-only'], JSON_THROW_ON_ERROR));
        chmod($this->path, 0400);
        chmod($this->directory, 0500);
        if (is_writable($this->directory)) {
            chmod($this->directory, 0700);
            chmod($this->path, 0600);
            $this->markTestSkipped('Current user can still write to a 0500 directory.');
        }

        try {
            $settings = (new SettingsFileStore($this->directory))->read($this->path);
            $this->assertSame('read-only', $settings['model'] ?? null);
            $this->assertFileDoesNotExist($this->path.'.lock');
        } finally {
            chmod($this->directory, 0700);
            chmod($this->path, 0600);
        }
    }

    public function test_concurrent_updates_do_not_overwrite_each_other(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for concurrent settings coverage.');
        }

        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork settings test worker.');
            }
            if ($pid === 0) {
                try {
                    $store = new SettingsFileStore($this->directory);
                    $store->update($this->path, function (array &$settings) use ($worker): void {
                        usleep(20_000);
                        $settings['worker_'.$worker] = $worker;
                    });
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $settings = (new SettingsFileStore($this->directory))->read($this->path);
        for ($worker = 0; $worker < 4; $worker++) {
            $this->assertSame($worker, $settings['worker_'.$worker] ?? null);
        }
    }

    public function test_reader_waits_for_first_writer_before_file_is_published(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for concurrent settings coverage.');
        }

        $ready = $this->directory.'/writer-ready';
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork first-write settings worker.');
        }
        if ($pid === 0) {
            try {
                (new SettingsFileStore($this->directory))->update(
                    $this->path,
                    function (array &$settings) use ($ready): void {
                        file_put_contents($ready, 'ready');
                        usleep(150_000);
                        $settings['permissions']['deny'] = ['Bash'];
                    },
                );
                exit(0);
            } catch (\Throwable) {
                exit(1);
            }
        }

        $deadline = microtime(true) + 2.0;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        $this->assertFileExists($ready);

        $startedAt = microtime(true);
        $settings = (new SettingsFileStore($this->directory))->read($this->path);
        $elapsed = microtime(true) - $startedAt;
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertGreaterThan(0.1, $elapsed);
        $this->assertSame(['Bash'], $settings['permissions']['deny'] ?? null);
    }
}

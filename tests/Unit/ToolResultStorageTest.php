<?php

namespace Tests\Unit;

use HaoCode\Services\ToolResult\ToolResultStorage;
use PHPUnit\Framework\TestCase;

class ToolResultStorageTest extends TestCase
{
    private string $testDir;
    private string $sessionRoot;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir().'/haocode_test_storage_'.bin2hex(random_bytes(6));
        mkdir($this->testDir, 0700, true);
        $this->sessionRoot = $this->testDir.'/sessions';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->testDir);
    }

    private function makeStorage(?string $sessionId = null): ToolResultStorage
    {
        return new ToolResultStorage(
            $this->sessionRoot,
            $sessionId ?? 'test_'.getmypid().'_'.bin2hex(random_bytes(5)),
        );
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }

    // ─── shouldPersist ──────────────────────────────────────────────────

    public function test_should_persist_returns_true_for_large_output(): void
    {
        $storage = $this->makeStorage();
        $largeOutput = str_repeat('x', 60000);

        $this->assertTrue($storage->shouldPersist($largeOutput, 50000));
    }

    public function test_should_persist_returns_false_for_small_output(): void
    {
        $storage = $this->makeStorage();

        $this->assertFalse($storage->shouldPersist('small', 50000));
    }

    // ─── persist ────────────────────────────────────────────────────────

    public function test_persist_writes_file_and_returns_info(): void
    {
        $storage = $this->makeStorage();
        $output = str_repeat("line content\n", 5000);

        $result = $storage->persist('test_tool_123', $output);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('filepath', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFileExists($result['filepath']);
        $this->assertStringContainsString('persisted-output', $result['message']);
        $this->assertStringStartsWith(
            realpath($this->testDir.'/sessions').DIRECTORY_SEPARATOR,
            (string) realpath($result['filepath']),
        );
        $this->assertMatchesRegularExpression(
            '/test_tool_123-[a-f0-9]{64}\.txt$/',
            $result['filepath'],
        );
    }

    public function test_persist_secures_session_directories_and_result_file(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $result = $this->makeStorage('secure_session')->persist('call_1', 'secret');

        $this->assertNotNull($result);
        clearstatcache(true);
        $this->assertSame(0700, fileperms($this->testDir.'/sessions') & 0777);
        $this->assertSame(0700, fileperms($this->testDir.'/sessions/secure_session') & 0777);
        $this->assertSame(0700, fileperms(dirname($result['filepath'])) & 0777);
        $this->assertSame(0600, fileperms($result['filepath']) & 0777);
        $this->assertSame([], glob(dirname($result['filepath']).'/.tool-result-*') ?: []);
    }

    public function test_persist_preserves_existing_safe_session_root_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $sessionRoot = $this->testDir.'/existing-sessions';
        mkdir($sessionRoot, 0755);
        chmod($sessionRoot, 0755);
        $this->sessionRoot = $sessionRoot;

        $result = $this->makeStorage('existing_safe')->persist('call_1', 'secret');

        $this->assertNotNull($result);
        clearstatcache(true);
        $this->assertSame(0755, fileperms($sessionRoot) & 0777);
        $this->assertSame(0700, fileperms($sessionRoot.'/existing_safe') & 0777);
        $this->assertSame(0700, fileperms(dirname($result['filepath'])) & 0777);
        $this->assertSame(0600, fileperms($result['filepath']) & 0777);
    }

    public function test_persist_rejects_existing_non_sticky_shared_session_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $sessionRoot = $this->testDir.'/unsafe-sessions';
        mkdir($sessionRoot, 0700);
        chmod($sessionRoot, 0777);
        $this->sessionRoot = $sessionRoot;

        $result = $this->makeStorage('unsafe_shared')->persist('call_1', 'secret');

        $this->assertNull($result);
        clearstatcache(true, $sessionRoot);
        $this->assertSame(0777, fileperms($sessionRoot) & 0777);
        $this->assertDirectoryDoesNotExist($sessionRoot.'/unsafe_shared');
    }

    public function test_persist_accepts_existing_sticky_shared_session_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not portable to Windows.');
        }

        $sessionRoot = $this->testDir.'/sticky-sessions';
        mkdir($sessionRoot, 0700);
        chmod($sessionRoot, 01777);
        $this->sessionRoot = $sessionRoot;

        $result = $this->makeStorage('sticky_shared')->persist('call_1', 'secret');

        $this->assertNotNull($result);
        clearstatcache(true);
        $this->assertSame(01777, fileperms($sessionRoot) & 01777);
        $this->assertSame(0700, fileperms($sessionRoot.'/sticky_shared') & 0777);
        $this->assertSame(0700, fileperms(dirname($result['filepath'])) & 0777);
    }

    public function test_constructor_rejects_invalid_session_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session id');

        new ToolResultStorage($this->testDir.'/sessions', '../escaped');
    }

    public function test_persist_fails_closed_when_session_root_is_a_symlink(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }

        $outside = sys_get_temp_dir().'/haocode_storage_outside_'.bin2hex(random_bytes(6));
        mkdir($outside, 0700, true);
        $sessionRoot = $this->testDir.'/sessions';
        if (! @symlink($outside, $sessionRoot)) {
            $this->removeTree($outside);
            $this->markTestSkipped('Unable to create symbolic link.');
        }

        try {
            $result = $this->makeStorage('root_symlink')->persist('call_1', 'secret');

            $this->assertNull($result);
            $this->assertDirectoryDoesNotExist($outside.'/root_symlink');
        } finally {
            @unlink($sessionRoot);
            $this->removeTree($outside);
        }
    }

    public function test_constructor_rejects_filesystem_root_without_changing_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX root permission assertion.');
        }

        clearstatcache(true, DIRECTORY_SEPARATOR);
        $modeBefore = fileperms(DIRECTORY_SEPARATOR) & 0777;
        try {
            new ToolResultStorage(DIRECTORY_SEPARATOR, 'root_rejected');
            $this->fail('Expected filesystem-root storage to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('filesystem root', $exception->getMessage());
        }

        clearstatcache(true, DIRECTORY_SEPARATOR);
        $this->assertSame($modeBefore, fileperms(DIRECTORY_SEPARATOR) & 0777);
    }

    // ─── generatePreview ────────────────────────────────────────────────

    public function test_preview_truncates_at_newline_boundary(): void
    {
        $storage = $this->makeStorage();
        $content = "short line\n" . str_repeat("a", 3000) . "\n";

        $preview = $storage->generatePreview($content, 2000);

        // Should not exceed 2000 chars
        $this->assertLessThanOrEqual(2000, mb_strlen($preview));
    }

    public function test_preview_returns_full_content_when_small(): void
    {
        $storage = $this->makeStorage();

        $this->assertSame('small content', $storage->generatePreview('small content', 2000));
    }

    // ─── enforceMessageBudget ───────────────────────────────────────────

    public function test_budget_does_not_modify_small_results(): void
    {
        $storage = $this->makeStorage();
        $results = [
            ['tool_use_id' => 'a', 'content' => 'small', 'is_error' => false],
            ['tool_use_id' => 'b', 'content' => 'also small', 'is_error' => false],
        ];

        $enforced = $storage->enforceMessageBudget($results);

        $this->assertSame('small', $enforced[0]['content']);
        $this->assertSame('also small', $enforced[1]['content']);
    }

    // ─── state tracking ─────────────────────────────────────────────────

    public function test_get_and_restore_state(): void
    {
        $sessionId = 'restore_'.bin2hex(random_bytes(4));
        $storage = $this->makeStorage($sessionId);
        $storage->persist('id_1', str_repeat('x', 100));

        $state = $storage->getState();
        $this->assertContains('id_1', $state['seenIds']);
        $this->assertArrayHasKey('id_1', $state['replacements']);

        // Create new storage and restore
        $storage2 = $this->makeStorage($sessionId);
        $storage2->restoreState($state);
        $state2 = $storage2->getState();

        $this->assertContains('id_1', $state2['seenIds']);
    }

    // ─── constants ──────────────────────────────────────────────────────

    public function test_preview_size_constant(): void
    {
        $this->assertSame(2000, ToolResultStorage::PREVIEW_SIZE_BYTES);
    }

    public function test_max_budget_constant(): void
    {
        $this->assertSame(120_000, ToolResultStorage::MAX_TOOL_RESULTS_PER_MESSAGE_CHARS);
        $this->assertSame(40_000, ToolResultStorage::MAX_SINGLE_RESULT_CHARS);
    }

    // ─── path-traversal hardening (chatgpt 3rd review #1) ──────────────
    //
    // tool_use_id flows verbatim from model/gateway output. A hostile
    // gateway could return `../../../../escaped` and the old code would
    // write outside the storage dir. The id is now sanitized before being
    // used as a filename, and a realpath boundary check guards the
    // already-existing case too.

    public function test_persist_rejects_traversal_in_tool_use_id(): void
    {
        $storage = $this->makeStorage();
        $victimDir = sys_get_temp_dir() . '/haocode_test_storage_victim_' . uniqid();
        @mkdir($victimDir, 0755, true);
        try {
            $victimFile = $victimDir . '/escaped.txt';
            $this->assertFileDoesNotExist($victimFile);

            $storage->persist('../../..' . ltrim($victimDir, '/') . '/escaped', 'leaked content');

            $this->assertFileDoesNotExist($victimFile, 'traversal id must NOT write outside storage dir');
        } finally {
            @unlink($victimDir . '/escaped.txt');
            @rmdir($victimDir);
        }
    }

    public function test_persist_with_traversal_id_still_writes_inside_storage(): void
    {
        // Even with a hostile id, persistence must still succeed inside the
        // storage dir (sanitized filename). Otherwise large-output handling
        // silently breaks for any model that emits an unusual id.
        $storage = $this->makeStorage();
        $output = str_repeat('x', 50000);

        $result = $storage->persist('../../etc/passwd', $output);

        $this->assertNotNull($result, 'sanitized persist should still succeed');
        $this->assertFileExists($result['filepath']);
        // The file lives inside the storage directory tree (after sanitization
        // the filename is `____etc_passwd.txt`).
        $realFile = realpath($result['filepath']);
        $this->assertNotFalse($realFile);
        $this->assertStringStartsWith(
            realpath($this->testDir.'/sessions').DIRECTORY_SEPARATOR,
            $realFile,
        );
    }

    public function test_persist_preserves_safe_id_unchanged(): void
    {
        // A readable prefix remains, while the complete original id is hashed
        // to prevent collisions after sanitization.
        $storage = $this->makeStorage();

        $result = $storage->persist('toolu_abc123', str_repeat('x', 50000));

        $this->assertNotNull($result);
        $this->assertStringEndsWith(
            '/toolu_abc123-'.hash('sha256', 'toolu_abc123').'.txt',
            $result['filepath'],
        );
    }

    public function test_persist_normalizes_slashes_in_id(): void
    {
        // `call_abc/def` from a gateway should be sanitized to a single file,
        // not interpreted as a subpath.
        $storage = $this->makeStorage();

        $result = $storage->persist('call_abc/def', str_repeat('x', 50000));

        $this->assertNotNull($result);
        $this->assertStringEndsWith(
            '/call_abc_def-'.hash('sha256', 'call_abc/def').'.txt',
            $result['filepath'],
        );
        $this->assertFileExists($result['filepath']);
    }

    public function test_restore_state_rejects_replacement_outside_current_session_root(): void
    {
        $sessionId = 'outside_'.bin2hex(random_bytes(4));
        $storage = $this->makeStorage($sessionId);
        $result = $storage->persist('id_outside', 'secret');
        $this->assertNotNull($result);

        $outside = $this->testDir.'/outside.txt';
        file_put_contents($outside, 'outside');
        $state = $storage->getState();
        $state['replacements']['id_outside'] = str_replace(
            $result['filepath'],
            $outside,
            $state['replacements']['id_outside'],
        );

        $restored = $this->makeStorage($sessionId);
        $restored->restoreState($state);

        $this->assertNotContains('id_outside', $restored->getState()['seenIds']);
        $this->assertArrayNotHasKey('id_outside', $restored->getState()['replacements']);
    }

    public function test_restore_state_rejects_missing_persisted_file(): void
    {
        $sessionId = 'missing_'.bin2hex(random_bytes(4));
        $storage = $this->makeStorage($sessionId);
        $result = $storage->persist('id_missing', 'secret');
        $this->assertNotNull($result);
        $state = $storage->getState();
        unlink($result['filepath']);

        $restored = $this->makeStorage($sessionId);
        $restored->restoreState($state);

        $this->assertArrayNotHasKey('id_missing', $restored->getState()['replacements']);
    }

    public function test_restore_state_rejects_persisted_file_symlinked_outside_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink behavior requires POSIX coverage.');
        }

        $sessionId = 'symlink_'.bin2hex(random_bytes(4));
        $storage = $this->makeStorage($sessionId);
        $result = $storage->persist('id_symlink', 'secret');
        $this->assertNotNull($result);
        $state = $storage->getState();

        $outside = $this->testDir.'/outside-secret.txt';
        file_put_contents($outside, 'outside');
        unlink($result['filepath']);
        symlink($outside, $result['filepath']);

        $restored = $this->makeStorage($sessionId);
        $restored->restoreState($state);

        $this->assertArrayNotHasKey('id_symlink', $restored->getState()['replacements']);
    }
}

<?php

namespace Tests\Unit;

use HaoCode\Services\ToolResult\ToolResultStorage;
use PHPUnit\Framework\TestCase;

class ToolResultStorageTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/haocode_test_storage_' . getmypid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            @rmdir($this->testDir);
        }
    }

    private function makeStorage(): ToolResultStorage
    {
        return new ToolResultStorage(uniqid('test_' . getmypid() . '_', true));
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
        $storage = $this->makeStorage();
        $storage->persist('id_1', str_repeat('x', 100));

        $state = $storage->getState();
        $this->assertContains('id_1', $state['seenIds']);
        $this->assertArrayHasKey('id_1', $state['replacements']);

        // Create new storage and restore
        $storage2 = $this->makeStorage();
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
        $this->assertStringContainsString('haocode/sessions', $realFile);
    }

    public function test_persist_preserves_safe_id_unchanged(): void
    {
        // Benign ids must pass through verbatim — no hashing that would hurt
        // debuggability.
        $storage = $this->makeStorage();

        $result = $storage->persist('toolu_abc123', str_repeat('x', 50000));

        $this->assertNotNull($result);
        $this->assertStringEndsWith('/toolu_abc123.txt', $result['filepath']);
    }

    public function test_persist_normalizes_slashes_in_id(): void
    {
        // `call_abc/def` from a gateway should be sanitized to a single file,
        // not interpreted as a subpath.
        $storage = $this->makeStorage();

        $result = $storage->persist('call_abc/def', str_repeat('x', 50000));

        $this->assertNotNull($result);
        $this->assertStringEndsWith('/call_abc_def.txt', $result['filepath']);
        $this->assertFileExists($result['filepath']);
    }
}

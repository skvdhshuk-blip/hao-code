<?php

namespace Tests\Unit;

use HaoCode\Services\Session\SessionManager;
use Tests\TestCase;

class SessionManagerTest extends TestCase
{
    public function test_persistence_can_be_disabled_for_ephemeral_queries(): void
    {
        $manager = new SessionManager(persistenceEnabled: false);

        $this->assertFalse($manager->isPersistenceEnabled());
        $manager->recordEntry(['type' => 'user_message', 'content' => 'temporary']);

        $sessionPath = config('haocode.session_path', storage_path('app/haocode/sessions'));
        $this->assertFileDoesNotExist($sessionPath.'/'.$manager->getSessionId().'.jsonl');
    }

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/haocode_session_test_' . getmypid();
        mkdir($this->tmpDir, 0755, true);

        config(['haocode.session_path' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        foreach (glob("{$this->tmpDir}/*.jsonl") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ─── getSessionId ─────────────────────────────────────────────────────

    public function test_session_id_is_non_empty(): void
    {
        $manager = new SessionManager;
        $this->assertNotEmpty($manager->getSessionId());
    }

    public function test_two_instances_have_different_session_ids(): void
    {
        $a = new SessionManager;
        $b = new SessionManager;
        $this->assertNotSame($a->getSessionId(), $b->getSessionId());
    }

    // ─── title ────────────────────────────────────────────────────────────

    public function test_title_starts_null(): void
    {
        $manager = new SessionManager;
        $this->assertNull($manager->getTitle());
    }

    public function test_set_and_get_title(): void
    {
        $manager = new SessionManager;
        $manager->setTitle('My Session');
        $this->assertSame('My Session', $manager->getTitle());
    }

    // ─── extractTitleFromEntries ──────────────────────────────────────────

    public function test_extract_title_returns_first_session_title(): void
    {
        $entries = [
            ['type' => 'user_message', 'content' => 'hello'],
            ['type' => 'session_title', 'title' => 'Found Title'],
            ['type' => 'session_title', 'title' => 'Second Title'],
        ];
        $this->assertSame('Found Title', SessionManager::extractTitleFromEntries($entries));
    }

    public function test_extract_title_returns_null_when_no_title_entry(): void
    {
        $entries = [
            ['type' => 'user_message', 'content' => 'hello'],
        ];
        $this->assertNull(SessionManager::extractTitleFromEntries($entries));
    }

    public function test_extract_title_returns_null_for_empty_entries(): void
    {
        $this->assertNull(SessionManager::extractTitleFromEntries([]));
    }

    // ─── recordEntry / loadSession ────────────────────────────────────────

    public function test_record_entry_creates_file(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry(['type' => 'test_event', 'data' => 'value']);

        $files = glob("{$this->tmpDir}/*.jsonl");
        $this->assertCount(1, $files);
    }

    public function test_load_session_returns_recorded_entries(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry(['type' => 'user_message', 'content' => 'Hello']);
        $manager->recordEntry(['type' => 'user_message', 'content' => 'World']);

        $entries = $manager->loadSession($manager->getSessionId());
        $this->assertCount(2, $entries);
        $this->assertSame('Hello', $entries[0]['content']);
        $this->assertSame('World', $entries[1]['content']);
    }

    public function test_load_session_returns_empty_for_unknown_id(): void
    {
        $manager = new SessionManager;
        $entries = $manager->loadSession('nonexistent_session_xyz');
        $this->assertSame([], $entries);
    }

    public function test_recorded_entry_includes_timestamp_and_session_id(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry(['type' => 'ping']);

        $entries = $manager->loadSession($manager->getSessionId());
        $this->assertNotEmpty($entries);
        $this->assertArrayHasKey('timestamp', $entries[0]);
        $this->assertArrayHasKey('session_id', $entries[0]);
        $this->assertSame($manager->getSessionId(), $entries[0]['session_id']);
    }

    // ─── recordTurn ───────────────────────────────────────────────────────

    public function test_record_turn_stores_assistant_message(): void
    {
        $manager = new SessionManager;
        $assistantMessage = ['role' => 'assistant', 'content' => 'I can help'];
        $manager->recordTurn($assistantMessage, []);

        $entries = $manager->loadSession($manager->getSessionId());
        $this->assertNotEmpty($entries);
        $this->assertSame('assistant_turn', $entries[0]['type']);
        $this->assertSame($assistantMessage, $entries[0]['message']);
    }

    public function test_interrupt_claim_is_single_use_and_fail_closed(): void
    {
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-1',
            'session_id' => $manager->getSessionId(),
            'actions' => [],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, ['blocks' => [], 'results' => []]);

        $claim = $manager->claimInterrupt($manager->getSessionId(), 'int-1', []);
        $this->assertSame('interrupt_resolving', $claim['type']);
        $this->assertSame('interrupt_resolving', $manager->getInterruptState($manager->getSessionId(), 'int-1')['type']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already resolving');
        $manager->claimInterrupt($manager->getSessionId(), 'int-1', []);
    }

    // ─── setTitle records entry ───────────────────────────────────────────

    public function test_set_title_records_session_title_entry(): void
    {
        $manager = new SessionManager;
        $manager->setTitle('Test Session Title');

        $entries = $manager->loadSession($manager->getSessionId());
        $this->assertNotEmpty($entries);
        $types = array_column($entries, 'type');
        $this->assertContains('session_title', $types);
    }

    public function test_load_session_skips_malformed_json_lines(): void
    {
        // Write a JSONL file with one valid and one invalid line directly
        $manager = new SessionManager;
        $sid = $manager->getSessionId();

        $filePath = $this->tmpDir . '/' . $sid . '.jsonl';
        $valid = json_encode(['type' => 'user_message', 'content' => 'hello']);
        file_put_contents($filePath, $valid . "\n" . "NOT VALID JSON\n" . $valid . "\n");

        $entries = $manager->loadSession($sid);

        // Should get 2 valid entries, not 3 (null from the malformed line was dropped)
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
        }
    }

    public function test_branch_session_creates_new_transcript_and_switches_session(): void
    {
        $manager = new SessionManager;
        $manager->setTitle('Feature Work');
        $manager->recordUserMessage('Implement the new command');
        $manager->recordTurn(['role' => 'assistant', 'content' => 'Working on it'], []);

        $branch = $manager->branchSession();

        $this->assertNotSame($branch['source_session_id'], $branch['session_id']);
        $this->assertSame($branch['session_id'], $manager->getSessionId());
        $this->assertSame($branch['title'], $manager->getTitle());
        $this->assertSame('Feature Work (Branch)', $branch['title']);

        $entries = $manager->loadSession($branch['session_id']);
        $this->assertNotEmpty($entries);
        $this->assertSame('session_title', $entries[0]['type']);
        $this->assertSame('session_branch', $entries[1]['type']);
        $this->assertSame($branch['source_session_id'], $entries[1]['source_session_id']);
    }

    public function test_find_most_recent_session_id_prefers_matching_cwd(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry(['type' => 'user_message', 'content' => 'current cwd']);

        $otherSessionId = '1999-01-01_000000_deadbeef';
        file_put_contents($this->tmpDir.'/'.$otherSessionId.'.jsonl', json_encode([
            'timestamp' => date('c', time() + 60),
            'session_id' => $otherSessionId,
            'cwd' => '/tmp/somewhere-else',
            'type' => 'user_message',
            'content' => 'other cwd',
        ])."\n");

        $this->assertSame($manager->getSessionId(), $manager->findMostRecentSessionId(getcwd()));
    }

    public function test_checkpoint_write_survives_invalid_utf8_and_non_finite_doubles(): void
    {
        $manager = new SessionManager;
        $binary = "\xB1\xBE\xB3\xAC\xFF\xFE"; // GBK bytes — invalid as UTF-8.
        $interrupt = [
            'id' => 'int-bin',
            'session_id' => $manager->getSessionId(),
            'actions' => [
                ['id' => 'act-1', 'toolName' => 'Bash', 'input' => ['command' => "cat {$binary}"], 'allowedDecisions' => ['approve', 'reject']],
            ],
            'created_at' => date('c'),
        ];
        $checkpoint = [
            'blocks' => [['type' => 'tool_result', 'output' => "file head {$binary} tail"]],
            'results' => [],
            'usage' => ['ratio' => INF, 'other' => -INF, 'nan' => NAN, 'ok' => 1.5],
        ];

        // Must not throw — the run is more important than one checkpoint line.
        $manager->recordPendingInterrupt($interrupt, $checkpoint);

        $filePath = $this->tmpDir.'/'.$manager->getSessionId().'.jsonl';
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines);
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, 'checkpoint line must be valid JSON after sanitizing');
        $this->assertSame('interrupt_pending', $decoded['type']);
        $this->assertSame('int-bin', $decoded['interrupt']['id']);
        // Non-finite doubles are replaced, not fatal (JSON has no float
        // fraction type — 0.0 may come back as int 0).
        $this->assertEquals(0.0, $decoded['checkpoint']['usage']['ratio']);
        $this->assertEquals(0.0, $decoded['checkpoint']['usage']['other']);
        $this->assertEquals(0.0, $decoded['checkpoint']['usage']['nan']);
        $this->assertSame(1.5, $decoded['checkpoint']['usage']['ok']);
        // Invalid UTF-8 was scrubbed: the remaining bytes are valid UTF-8.
        $this->assertSame(1, preg_match('//u', $decoded['interrupt']['actions'][0]['input']['command']));
        $this->assertSame(1, preg_match('//u', $decoded['checkpoint']['blocks'][0]['output']));
    }

    public function test_checkpoint_write_keeps_valid_utf8_untouched(): void
    {
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-utf8',
            'session_id' => $manager->getSessionId(),
            'actions' => [],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, ['blocks' => [], 'results' => [], 'note' => '继续（中文）éè']);

        $entries = $manager->loadSession($manager->getSessionId());
        $pending = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['type'] ?? null) === 'interrupt_pending'));
        $this->assertNotEmpty($pending);
        $this->assertSame('继续（中文）éè', $pending[0]['checkpoint']['note']);
    }
}

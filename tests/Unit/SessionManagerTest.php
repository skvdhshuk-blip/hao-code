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

    /**
     * @dataProvider traversalSessionIdProvider
     */
    public function test_load_session_rejects_traversal_ids(string $maliciousId): void
    {
        $manager = new SessionManager;

        $this->expectException(\InvalidArgumentException::class);
        $manager->loadSession($maliciousId);
    }

    /**
     * @dataProvider traversalSessionIdProvider
     */
    public function test_switch_to_session_rejects_traversal_ids(string $maliciousId): void
    {
        $manager = new SessionManager;

        $this->expectException(\InvalidArgumentException::class);
        $manager->switchToSession($maliciousId);
    }

    /**
     * @dataProvider traversalSessionIdProvider
     */
    public function test_claim_interrupt_rejects_traversal_ids(string $maliciousId): void
    {
        $manager = new SessionManager;

        $this->expectException(\InvalidArgumentException::class);
        $manager->claimInterrupt($maliciousId, 'int-1', []);
    }

    public static function traversalSessionIdProvider(): array
    {
        return [
            'parent dir'        => ['../../etc/passwd'],
            'absolute path'     => ['/etc/passwd'],
            'leading slash'     => ['/outside'],
            'trailing slash'    => ['legit/'],
            'backslash'         => ['legit\\.jsonl'],
            'null byte'         => ["legit\0evil"],
            'glob star'         => ['*.jsonl'],
            'glob question'     => ['legit?'],
            'glob bracket'      => ['legit[abc]'],
            'empty'             => [''],
        ];
    }

    public function test_load_session_rejects_traversal_does_not_touch_target(): void
    {
        // Pre-create a real session, then attempt to read a sibling via traversal.
        // The guard must throw before any file access happens.
        $manager = new SessionManager;
        $manager->recordEntry(['type' => 'user_message', 'content' => 'real']);
        $realId = $manager->getSessionId();

        $victim = $this->tmpDir . '/victim.jsonl';
        file_put_contents($victim, json_encode(['type' => 'user_message', 'content' => 'secret']) . "\n");

        try {
            $manager->loadSession('../victim');
            $this->fail('Expected InvalidArgumentException for traversal session id.');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        // The victim file is untouched and still readable directly.
        $this->assertFileExists($victim);
    }

    // ─── partial ID resolution (chatgpt 3rd review #9) ─────────────────

    public function test_partial_id_resolves_to_canonical_and_switches_to_it(): void
    {
        // Two sessions sharing a date prefix; loadSession with a unique-enough
        // partial must resolve to the canonical id and expose it via
        // getLastResolvedSessionId(). Writes after the load go to the right file.
        $fullId = '2026-07-20_120000_abcd1234';
        file_put_contents(
            $this->tmpDir.'/'.$fullId.'.jsonl',
            json_encode(['type' => 'user_message', 'content' => 'first'])."\n",
        );

        $manager = new SessionManager;
        $entries = $manager->loadSession('2026-07-20_120000_abcd');

        $this->assertCount(1, $entries);
        $this->assertSame($fullId, $manager->getLastResolvedSessionId(), 'canonical id must be exposed after partial-id load');

        // Switching and writing must land in the canonical file, not a
        // ghost file named after the partial id.
        $manager->switchToSession($manager->getLastResolvedSessionId());
        $manager->recordEntry(['type' => 'user_message', 'content' => 'second']);

        $this->assertFileExists($this->tmpDir.'/'.$fullId.'.jsonl');
        $this->assertFileDoesNotExist($this->tmpDir.'/2026-07-20_120000_abcd.jsonl');
    }

    public function test_partial_id_with_multiple_matches_throws_ambiguous_exception(): void
    {
        // Two sessions sharing a prefix. The caller must disambiguate —
        // silently picking the first match caused read-A-write-B split-brain.
        file_put_contents($this->tmpDir.'/2026-07-20_120000_aaaa.jsonl', json_encode(['type' => 'user_message', 'content' => 'a'])."\n");
        file_put_contents($this->tmpDir.'/2026-07-20_120000_bbbb.jsonl', json_encode(['type' => 'user_message', 'content' => 'b'])."\n");

        $manager = new SessionManager;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ambiguous session id/');
        $manager->loadSession('2026-07-20');
    }

    public function test_find_session_id_returns_null_when_nothing_matches(): void
    {
        $manager = new SessionManager;

        $this->assertNull($manager->findSessionId('nonexistent-prefix-xyz'));
    }

    public function test_find_session_id_returns_canonical_for_unique_match(): void
    {
        $fullId = '2026-07-20_180000_efgh5678';
        file_put_contents(
            $this->tmpDir.'/'.$fullId.'.jsonl',
            json_encode(['type' => 'user_message', 'content' => 'first'])."\n",
        );

        $manager = new SessionManager;

        $this->assertSame($fullId, $manager->findSessionId('2026-07-20_18'));
    }

    // ─── interrupt checkpoint durable persistence (chatgpt 3rd review #8) ──

    public function test_record_pending_interrupt_throws_when_session_dir_is_unwritable(): void
    {
        // chatgpt #8: recordPendingInterrupt used to call best-effort
        // recordEntry(), so a disk failure silently dropped the checkpoint
        // while the caller still raised a HumanInterrupt. The durable path
        // must throw so the caller knows the checkpoint didn't land.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root bypasses file permissions; cannot simulate read-only dir.');
        }

        $readOnlyDir = $this->tmpDir . '/readonly_' . uniqid();
        mkdir($readOnlyDir, 0500, true);
        $manager = new SessionManager;
        // Force the manager onto the read-only path by recording once
        // successfully, then locking the directory.
        config(['haocode.session_path' => $readOnlyDir]);
        // Re-create manager so it picks up the new path.
        $manager = new SessionManager;
        $manager->switchToSession('test-readonly-' . uniqid());

        chmod($readOnlyDir, 0500);
        try {
            $this->expectException(\RuntimeException::class);
            $manager->recordPendingInterrupt(
                ['id' => 'int-1', 'session_id' => $manager->getSessionId(), 'actions' => [], 'created_at' => date('c')],
                ['blocks' => [], 'results' => []],
            );
        } finally {
            chmod($readOnlyDir, 0700);
            // tearDown will clean up.
        }
    }

    public function test_record_pending_interrupt_durable_happy_path(): void
    {
        // Sanity check: the durable write path still records the interrupt
        // correctly when the disk is healthy.
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-durable',
            'session_id' => $manager->getSessionId(),
            'actions' => [],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, ['blocks' => [], 'results' => []]);

        $entries = $manager->loadSession($manager->getSessionId());
        $pending = array_values(array_filter(
            $entries,
            static fn (array $e): bool => ($e['type'] ?? null) === 'interrupt_pending',
        ));
        $this->assertNotEmpty($pending);
        $this->assertSame('int-durable', $pending[0]['interrupt']['id']);
    }

    public function test_pending_interrupt_can_be_cancelled_and_cannot_be_claimed(): void
    {
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-cancel',
            'session_id' => $manager->getSessionId(),
            'actions' => [['id' => 'call-1', 'tool_name' => 'Bash']],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt(
            $interrupt,
            ['assistant_message' => ['role' => 'assistant', 'content' => []]],
        );

        $manager->cancelInterrupt(
            $manager->getSessionId(),
            'int-cancel',
            'Background task stopped.',
        );

        $state = $manager->getInterruptState($manager->getSessionId(), 'int-cancel');
        $this->assertSame('interrupt_cancelled', $state['type']);
        $this->assertSame('call-1', $state['tool_results'][0]['tool_use_id']);
        $this->assertTrue($state['tool_results'][0]['is_error']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already cancelled');
        $manager->claimInterrupt($manager->getSessionId(), 'int-cancel', []);
    }

    public function test_cancelling_mixed_batch_preserves_completed_results(): void
    {
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-mixed-cancel',
            'session_id' => $manager->getSessionId(),
            'actions' => [['id' => 'call-write', 'tool_name' => 'Write']],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, [
            'blocks' => [
                ['id' => 'call-read', 'name' => 'Read', 'input' => []],
                ['id' => 'call-write', 'name' => 'Write', 'input' => []],
            ],
            'results' => [
                0 => [
                    'type' => 'tool_result',
                    'tool_use_id' => 'call-read',
                    'content' => 'existing content',
                    'is_error' => false,
                ],
            ],
        ]);

        $manager->cancelInterrupt(
            $manager->getSessionId(),
            'int-mixed-cancel',
            'Stopped.',
        );

        $results = $manager->getInterruptState(
            $manager->getSessionId(),
            'int-mixed-cancel',
        )['tool_results'];
        $this->assertCount(2, $results);
        $this->assertSame('call-read', $results[0]['tool_use_id']);
        $this->assertSame('existing content', $results[0]['content']);
        $this->assertSame('call-write', $results[1]['tool_use_id']);
        $this->assertTrue($results[1]['is_error']);
    }

    public function test_cancelling_child_interrupt_also_cancels_pending_parent_chain(): void
    {
        $manager = new SessionManager;
        $parentSession = 'parent-'.bin2hex(random_bytes(4));
        $childSession = 'child-'.bin2hex(random_bytes(4));

        $manager->switchToSession($parentSession);
        $manager->recordPendingInterrupt([
            'id' => 'int-parent',
            'session_id' => $parentSession,
            'actions' => [['id' => 'parent-call', 'tool_name' => 'Agent']],
            'created_at' => date('c'),
        ], ['assistant_message' => ['role' => 'assistant', 'content' => []]]);

        $manager->switchToSession($childSession);
        $manager->recordPendingInterrupt([
            'id' => 'int-child',
            'session_id' => $childSession,
            'actions' => [['id' => 'child-call', 'tool_name' => 'Bash']],
            'created_at' => date('c'),
        ], ['assistant_message' => ['role' => 'assistant', 'content' => []]]);
        $manager->recordInterruptParentLink(
            $childSession,
            'int-child',
            $parentSession,
            'int-parent',
            'parent-call',
        );

        $manager->cancelInterrupt($childSession, 'int-child', 'Background task stopped.');

        $this->assertSame(
            'interrupt_cancelled',
            $manager->getInterruptState($childSession, 'int-child')['type'],
        );
        $this->assertSame(
            'interrupt_cancelled',
            $manager->getInterruptState($parentSession, 'int-parent')['type'],
        );
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

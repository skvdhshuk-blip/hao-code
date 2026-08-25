<?php

namespace Tests\Unit;

use Tests\TestCase;

trait SessionManagerTestTestRecordTurnStoresAssistantMessageConcern
{

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

    public function test_corrupt_interrupt_jsonl_line_does_not_roll_back_to_pending(): void
    {
        $manager = new SessionManager;
        $sessionId = $manager->getSessionId();
        $interrupt = [
            'id' => 'int-corrupt',
            'session_id' => $sessionId,
            'actions' => [],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, ['blocks' => [], 'results' => []]);
        $manager->claimInterrupt($sessionId, 'int-corrupt', []);

        $path = $this->tmpDir.'/'.$sessionId.'.jsonl';
        $lines = file($path);
        $this->assertIsArray($lines);
        $lines[count($lines) - 1] = '{"type":"interrupt_resolving","interrupt":{"id":"int-corrupt"'.PHP_EOL;
        file_put_contents($path, implode('', $lines));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/indeterminate/i');
        $manager->getInterruptState($sessionId, 'int-corrupt');
    }

    public function test_fail_interrupt_marks_resolving_as_failed_terminal_state(): void
    {
        $manager = new SessionManager;
        $interrupt = [
            'id' => 'int-fail',
            'session_id' => $manager->getSessionId(),
            'actions' => [],
            'created_at' => date('c'),
        ];
        $manager->recordPendingInterrupt($interrupt, ['blocks' => [], 'results' => []]);
        $manager->claimInterrupt($manager->getSessionId(), 'int-fail', []);

        $manager->failInterrupt(
            $manager->getSessionId(),
            'int-fail',
            'provider timeout',
            'partial',
            [['tool_use_id' => 't1', 'content' => 'done', 'is_error' => false]],
        );

        $state = $manager->getInterruptState($manager->getSessionId(), 'int-fail');
        $this->assertSame('interrupt_failed', $state['type']);
        $this->assertSame('provider timeout', $state['error']);
        $this->assertSame('partial', $state['side_effect_status']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already failed');
        $manager->claimInterrupt($manager->getSessionId(), 'int-fail', []);
    }

    public function test_branch_session_rejects_unfinished_interrupt(): void
    {
        $manager = new SessionManager;
        $manager->recordUserMessage('hello');
        $manager->recordPendingInterrupt([
            'id' => 'int-branch',
            'session_id' => $manager->getSessionId(),
            'actions' => [],
            'created_at' => date('c'),
        ], ['blocks' => [], 'results' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unfinished human interrupt');
        $manager->branchSession();
    }

    public function test_set_title_records_session_title_entry(): void
    {
        $manager = new SessionManager;
        $manager->setTitle('Test Session Title');

        $entries = $manager->loadSession($manager->getSessionId());
        $this->assertNotEmpty($entries);
        $types = array_column($entries, 'type');
        $this->assertContains('session_title', $types);
    }

    public function test_load_session_rejects_malformed_json_lines(): void
    {
        $manager = new SessionManager;
        $sid = $manager->getSessionId();

        $filePath = $this->tmpDir . '/' . $sid . '.jsonl';
        $valid = json_encode(['type' => 'user_message', 'content' => 'hello']);
        file_put_contents($filePath, $valid . "\n" . "NOT VALID JSON\n" . $valid . "\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Session {$sid} contains invalid JSON on line 2");
        $manager->loadSession($sid);
    }

    public function test_load_session_rejects_unterminated_malformed_final_line(): void
    {
        $manager = new SessionManager;
        $sid = $manager->getSessionId();
        $filePath = $this->tmpDir . '/' . $sid . '.jsonl';
        file_put_contents(
            $filePath,
            json_encode(['type' => 'user_message', 'content' => 'hello'])."\n".'{"type":',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Session {$sid} contains invalid JSON on line 2");
        $manager->loadSession($sid);
    }

    public function test_load_session_accepts_valid_final_line_without_newline(): void
    {
        $manager = new SessionManager;
        $sid = $manager->getSessionId();
        $filePath = $this->tmpDir . '/' . $sid . '.jsonl';
        file_put_contents(
            $filePath,
            json_encode(['type' => 'user_message', 'content' => 'hello']),
        );

        $entries = $manager->loadSession($sid);

        $this->assertCount(1, $entries);
        $this->assertSame('hello', $entries[0]['content']);
    }

    public function test_load_session_waits_for_locked_writer_and_never_observes_partial_line(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for concurrent session coverage.');
        }

        $manager = new SessionManager;
        $sid = $manager->getSessionId();
        $path = $this->tmpDir.'/'.$sid.'.jsonl';
        $ready = $this->tmpDir.'/writer-ready';
        $line = json_encode(['type' => 'user_message', 'content' => 'complete'])."\n";
        $split = intdiv(strlen($line), 2);
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork session writer.');
        }
        if ($pid === 0) {
            $handle = fopen($path, 'c+');
            if ($handle === false || ! flock($handle, LOCK_EX)) {
                exit(1);
            }
            ftruncate($handle, 0);
            fwrite($handle, substr($line, 0, $split));
            fflush($handle);
            file_put_contents($ready, 'ready');
            usleep(150_000);
            fwrite($handle, substr($line, $split));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            exit(0);
        }

        $deadline = microtime(true) + 2.0;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        $this->assertFileExists($ready);

        $startedAt = microtime(true);
        $entries = $manager->loadSession($sid);
        $elapsed = microtime(true) - $startedAt;
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertGreaterThan(0.1, $elapsed);
        $this->assertSame('complete', $entries[0]['content']);
        @unlink($ready);
    }

    public function test_branch_session_creates_new_transcript_and_switches_session(): void
    {
        $manager = new SessionManager;
        $manager->setTitle('Feature Work');
        $manager->recordUserMessage('Implement the new command');
        $manager->recordTurn(['role' => 'assistant', 'content' => 'Working on it'], []);
        $manager->recordEntry(['type' => 'run_event', 'event' => ['run_id' => $manager->getSessionId()]]);
        $manager->recordEntry(['type' => 'run_checkpoint', 'checkpoint' => ['status' => 'completed']]);

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
        $this->assertNotContains('run_event', array_column($entries, 'type'));
        $this->assertNotContains('run_checkpoint', array_column($entries, 'type'));
    }

    public function test_branch_session_derives_title_from_multimodal_text_blocks(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry([
            'type' => 'user_message',
            'content' => [
                ['type' => 'text', 'text' => 'Inspect this diagram'],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/png',
                        'data' => 'aGVsbG8=',
                    ],
                ],
            ],
        ]);

        $branch = $manager->branchSession();

        $this->assertSame('Inspect this diagram (Branch)', $branch['title']);
        $this->assertStringNotContainsString('Array', $branch['title']);
    }

    public function test_branch_session_uses_json_sanitization_for_invalid_utf8(): void
    {
        $manager = new SessionManager;
        $manager->recordEntry([
            'type' => 'user_message',
            'content' => "binary \xFF input",
        ]);

        $branch = $manager->branchSession('Binary transcript');
        $entries = $manager->loadSession($branch['session_id']);

        $this->assertNotEmpty($entries);
        $this->assertSame('Binary transcript (Branch)', $branch['title']);
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

    public function test_find_most_recent_session_id_uses_file_mtime_without_loading_transcripts(): void
    {
        $olderId = '2026-01-01_000000_older';
        $newerId = '2026-01-01_000001_newer';
        $header = static function (string $id, string $timestamp): string {
            return json_encode([
                'timestamp' => $timestamp,
                'session_id' => $id,
                'cwd' => '/tmp/session-performance-test',
                'type' => 'user_message',
                'content' => 'header',
            ])."\n";
        };

        $olderPath = $this->tmpDir.'/'.$olderId.'.jsonl';
        $newerPath = $this->tmpDir.'/'.$newerId.'.jsonl';
        file_put_contents($olderPath, $header($olderId, '2030-01-01T00:00:00+00:00'));
        file_put_contents($newerPath, $header($newerId, '2020-01-01T00:00:00+00:00'));
        // Contradict the JSON timestamps deliberately: selection should use
        // the append-driven filesystem mtime, not parse every transcript.
        touch($olderPath, time() - 10);
        touch($newerPath, time());

        $this->assertSame($newerId, (new SessionManager)->findMostRecentSessionId());
    }

    public function test_find_most_recent_session_id_preserves_later_cwd_matches_without_loading_entries(): void
    {
        $sessionId = '2026-01-01_000002_worktree';
        $path = $this->tmpDir.'/'.$sessionId.'.jsonl';
        file_put_contents($path, implode("\n", [
            json_encode([
                'timestamp' => '2026-01-01T00:00:00+00:00',
                'session_id' => $sessionId,
                'cwd' => '/tmp/project',
                'type' => 'user_message',
                'content' => 'start',
            ]),
            json_encode([
                'timestamp' => '2026-01-01T00:00:01+00:00',
                'session_id' => $sessionId,
                'cwd' => '/tmp/project/.worktree',
                'type' => 'tool_result',
                'content' => 'worktree transition',
            ]),
            '',
        ]));

        $this->assertSame(
            $sessionId,
            (new SessionManager)->findMostRecentSessionId('/tmp/project/.worktree'),
        );
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

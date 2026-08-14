<?php

declare(strict_types=1);

namespace Tests\Feature;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Sdk\StructuredResultValidationException;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

trait SdkE2ETestTestForegroundWorktreeAgentFinalizesAndReportsAfterInterruptResumeConcern
{

    public function test_foreground_worktree_agent_finalizes_and_reports_after_interrupt_resume(): void
    {
        exec('git -C '.escapeshellarg($this->projectDir).' init -q', $output, $code);
        $this->assertSame(0, $code);
        exec('git -C '.escapeshellarg($this->projectDir).' config user.email test@example.test');
        exec('git -C '.escapeshellarg($this->projectDir).' config user.name "HaoCode Test"');
        file_put_contents($this->projectDir.'/README.md', "root\n");
        exec('git -C '.escapeshellarg($this->projectDir).' add README.md');
        exec('git -C '.escapeshellarg($this->projectDir).' commit -qm initial', $output, $code);
        $this->assertSame(0, $code);

        $worktreePath = null;
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('worktree-agent', 'Agent', [
                'description' => 'Write in isolated worktree',
                'prompt' => 'Write isolated.txt with the word isolated.',
                'subagent_type' => 'general-purpose',
                'isolation' => 'worktree',
            ]),
            MockAnthropicSse::toolUseResponse('worktree-write', 'Write', [
                'file_path' => 'isolated.txt',
                'content' => 'isolated',
            ]),
            MockAnthropicSse::textResponse('Child completed isolated work.'),
            function (array $payload) use (&$worktreePath): MockResponse {
                $childResult = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Worktree with changes retained at:', $childResult);
                preg_match('/retained at: (.+) \\(branch:/', $childResult, $matches);
                $worktreePath = $matches[1] ?? null;

                return MockAnthropicSse::textResponse('Parent completed after isolated child.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Agent', 'Write'],
            permissionMode: 'bypass_permissions',
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Delegate isolated work', $config);
            $this->fail('Expected child worktree interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $snapshot = $state['checkpoint']['run_snapshot'];
        $this->assertTrue($snapshot['managed_worktree']);
        $this->assertDirectoryExists($snapshot['worktree_path']);

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('worktree-write')],
            $config,
        );

        $this->assertIsString($worktreePath);
        $this->assertSame($worktreePath, $result->usage['worktree_path']);
        $this->assertTrue($result->usage['worktree_retained']);
        $this->assertStringContainsString($worktreePath, $result->text);
        $this->assertSame('isolated', file_get_contents($worktreePath.'/isolated.txt'));
        $this->assertFileDoesNotExist($this->projectDir.'/isolated.txt');
    }

    public function test_approved_agent_gate_can_pause_again_for_a_child_interrupt_without_reexecution(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('gated-agent', 'Agent', [
                'description' => 'Run gated child',
                'prompt' => 'Write gated-child.txt with the word child.',
            ]),
            MockAnthropicSse::toolUseResponse('gated-child-write', 'Write', [
                'file_path' => 'gated-child.txt',
                'content' => 'child',
            ]),
            MockAnthropicSse::textResponse('Gated child completed.'),
            function (array $payload): MockResponse {
                $agentToolUses = 0;
                foreach ($payload['messages'] ?? [] as $message) {
                    if (($message['role'] ?? null) !== 'assistant') {
                        continue;
                    }
                    foreach ($message['content'] ?? [] as $block) {
                        if (($block['type'] ?? null) === 'tool_use'
                            && ($block['id'] ?? null) === 'gated-agent') {
                            $agentToolUses++;
                        }
                    }
                }
                $this->assertSame(1, $agentToolUses);

                return MockAnthropicSse::textResponse('Gated parent completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Agent', 'Write'],
            ephemeral: false,
            interruptOn: ['Agent' => true, 'Write' => true],
            hitlMode: 'ask',
        );
        try {
            HaoCode::query('Run a gated child', $config);
            $this->fail('Expected parent agent gate.');
        } catch (HumanInterruptException $e) {
            $parentInterrupt = $e->interrupt;
        }
        try {
            HaoCode::resumeInterrupt($parentInterrupt->sessionId, $parentInterrupt->id, [
                HumanDecision::approve('gated-agent'),
            ], $config);
            $this->fail('Expected child write gate.');
        } catch (HumanInterruptException $e) {
            $childInterrupt = $e->interrupt;
        }
        $childState = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($childInterrupt->sessionId, $childInterrupt->id);
        $snapshot = $childState['checkpoint']['run_snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('general-purpose', $snapshot['agent_type']);
        $this->assertSame(realpath($this->projectDir), realpath($snapshot['cwd']));
        $this->assertSame(49, $snapshot['max_turns_remaining']);

        $result = HaoCode::resumeInterrupt($childInterrupt->sessionId, $childInterrupt->id, [
            HumanDecision::approve('gated-child-write'),
        ], $config);

        $this->assertSame('child', file_get_contents($this->projectDir.'/gated-child.txt'));
        $this->assertSame('Gated parent completed.', $result->text);
    }

    public function test_resume_redirect_forwards_images_to_the_conversation(): void
    {
        $payloads = [];
        $this->bootWithMock([
            function (array $payload) use (&$payloads): MockResponse {
                $payloads[] = $payload;
                return MockAnthropicSse::textResponse('first');
            },
            function (array $payload) use (&$payloads): MockResponse {
                $payloads[] = $payload;
                return MockAnthropicSse::textResponse('second');
            },
        ]);

        $first = HaoCode::query('hi', new HaoCodeConfig(ephemeral: false));
        $this->assertNotNull($first->sessionId);

        // Regression: resuming a session through the HaoCode facade must carry
        // config images into the conversation (the v1.15.0 Runner refactor
        // dropped them on the resume redirect).
        HaoCode::query('look at this', new HaoCodeConfig(
            ephemeral: false,
            sessionId: $first->sessionId,
            images: ['data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='],
        ));

        $this->assertCount(2, $payloads);
        $messages = $payloads[1]['messages'] ?? [];
        $lastUser = end($messages);
        $blocks = is_array($lastUser['content'] ?? null) ? $lastUser['content'] : [];
        $this->assertContains('image', array_column($blocks, 'type'));
    }

    private function bootWithMock(
        array $responses,
        ?array &$capturedRequests = null,
        string $model = 'claude-test',
    ): void
    {
        $requests = [];
        $this->refreshApplication();
        $this->app->useStoragePath($this->storageDir);

        $_SERVER['HAOCODE_STORAGE_PATH'] = $this->storageDir;
        putenv('HAOCODE_STORAGE_PATH='.$this->storageDir);

        config([
            'haocode.api_key' => 'test-key',
            'haocode.api_base_url' => 'https://mock.anthropic.test',
            'haocode.model' => $model,
            'haocode.max_tokens' => 4096,
            'haocode.permission_mode' => 'bypass_permissions',
            // These E2E tests exercise interrupt mechanics under 'ask'; pin the
            // process default so the config-file default ('smart') stays untested here.
            'haocode.hitl_mode' => 'ask',
            'haocode.global_settings_path' => $this->homeDir.'/.haocode/settings.json',
            'haocode.session_path' => $this->sessionDir,
            'haocode.api_stream_idle_timeout' => 2,
            'haocode.api_stream_poll_timeout' => 0.01,
        ]);

        $this->app->singleton(StreamingClient::class, function ($app) use (&$requests, $responses, $model): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: $model,
                baseUrl: 'https://mock.anthropic.test',
                maxTokens: 4096,
                httpClient: MockAnthropicSse::client($responses, $requests),
                settingsManager: $app->make(SettingsManager::class),
                idleTimeoutSeconds: 2,
                streamPollTimeoutSeconds: 0.01,
            );
        });

        if ($capturedRequests !== null) {
            $capturedRequests = &$requests;
        }
    }

    private function setHomeDirectory(string $home): void
    {
        if ($home === '') {
            putenv('HOME');
            unset($_SERVER['HOME']);

            return;
        }

        putenv('HOME='.$home);
        $_SERVER['HOME'] = $home;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    public function test_structured_accepts_response_that_satisfies_schema(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"high"}'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ]);

        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result['priority']);
    }

    public function test_structured_retries_once_when_model_returns_invalid_enum(): void
    {
        // First response violates the enum; second response is valid. Track
        // request count via a closure response so we don't depend on the
        // bootWithMock captured-reference helper.
        $requestCount = 0;
        $lastPayload = null;
        $this->bootWithMock([
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;
                return MockAnthropicSse::textResponse('{"category":"shipping","priority":"urgent"}');
            },
            function (array $payload) use (&$requestCount, &$lastPayload): MockResponse {
                $requestCount++;
                $lastPayload = $payload;
                return MockAnthropicSse::textResponse('{"category":"shipping","priority":"high"}');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ]);

        $this->assertSame('high', $result['priority']);
        // Confirm the retry actually happened: two provider requests were made.
        $this->assertSame(2, $requestCount, 'invalid first response must trigger exactly one retry');
        // Correction is a new user turn on the same conversation (not messages[0]).
        $secondPromptStr = (string) (MockAnthropicSse::lastUserText($lastPayload) ?? '');
        $this->assertStringContainsString('Your previous response was not acceptable', $secondPromptStr);
    }

    public function test_structured_retries_share_one_total_budget(): void
    {
        $invalid = '{"category":"shipping","priority":"urgent"}';
        $valid = '{"category":"shipping","priority":"high"}';
        $model = 'claude-sonnet-4-6';
        $this->bootWithMock([
            MockAnthropicSse::textResponse($invalid, model: $model),
            MockAnthropicSse::textResponse($valid, model: $model),
        ], model: $model);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this ticket.', [
            'type' => 'object',
            'required' => ['category', 'priority'],
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['enum' => ['low', 'medium', 'high']],
            ],
        ], new HaoCodeConfig(
            maxBudgetUsd: 1.0,
        ));

        $expectedCost = (
            64 * 3
            + (strlen($invalid) + strlen($valid)) * 15
        ) / 1_000_000;
        $this->assertNotNull($result->queryResult);
        $this->assertEqualsWithDelta(
            $expectedCost,
            $result->queryResult->cost,
            0.000001,
        );
    }

    public function test_structured_throws_when_retry_exhausted(): void
    {
        // Both responses violate the enum. Default maxRetries=1 means one retry,
        // so the second invalid response must throw.
        $this->bootWithMock([
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"urgent"}'),
            MockAnthropicSse::textResponse('{"category":"shipping","priority":"asap"}'),
        ]);

        chdir($this->projectDir);

        try {
            HaoCode::structured('Classify this ticket.', [
                'type' => 'object',
                'required' => ['category', 'priority'],
                'properties' => [
                    'category' => ['type' => 'string'],
                    'priority' => ['enum' => ['low', 'medium', 'high']],
                ],
            ]);
            $this->fail('Expected StructuredResultValidationException');
        } catch (StructuredResultValidationException $e) {
            $this->assertNotEmpty($e->validationErrors);
            $this->assertStringContainsString('schema validation', $e->getMessage());
            // Raw response + at least one error path are surfaced for diagnosis.
            $this->assertNotSame('', $e->rawResponse);
        }
    }

    public function test_structured_respects_zero_max_retries(): void
    {
        // With structuredMaxRetries=0, a single invalid response throws
        // immediately without a second request.
        $requestCount = 0;
        $this->bootWithMock([
            function (array $payload) use (&$requestCount): MockResponse {
                $requestCount++;
                return MockAnthropicSse::textResponse('{"category":"shipping"}');
            },
        ]);

        chdir($this->projectDir);

        try {
            HaoCode::structured('Classify this ticket.', [
                'type' => 'object',
                'required' => ['category', 'priority'],
                'properties' => [
                    'category' => ['type' => 'string'],
                    'priority' => ['enum' => ['low', 'medium', 'high']],
                ],
            ], new HaoCodeConfig(structuredMaxRetries: 0));
            $this->fail('Expected StructuredResultValidationException');
        } catch (StructuredResultValidationException $e) {
            // Only one provider request — no retry attempted.
            $this->assertSame(1, $requestCount, 'structuredMaxRetries=0 must not retry');
            $this->assertStringContainsString('priority', implode(' ', $e->validationErrors));
        }
    }
}

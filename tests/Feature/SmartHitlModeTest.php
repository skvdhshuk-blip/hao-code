<?php

declare(strict_types=1);

namespace Tests\Feature;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Message;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

/**
 * E2E coverage for the smart/auto HITL modes wired into the SDK agent loop:
 * rule auto-approvals suppress interrupts, red-line batches escalate with
 * auto_decision events before the interrupt, auto mode suppresses tool
 * interrupts entirely, and AskUserQuestion still reaches a human.
 */
class SmartHitlModeTest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-smart-hitl-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->projectDir = $this->tempRoot.'/project';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/sdk-storage';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->projectDir, 0755, true);
        mkdir($this->sessionDir, 0755, true);
        mkdir($this->storageDir, 0755, true);

        $this->setHomeDirectory($this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        unset($_SERVER['HAOCODE_STORAGE_PATH']);
        putenv('HAOCODE_STORAGE_PATH');
        $this->setHomeDirectory($this->originalHome);
        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────
    //  smart mode
    // ──────────────────────────────────────────────────────────────

    public function test_smart_mode_auto_approves_rule_allowlisted_batch_without_interrupt(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('smart-pwd', 'Bash', [
                'command' => 'pwd',
                'description' => 'Print working directory',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString($this->projectDir, (string) MockAnthropicSse::lastToolResultText($payload));

                return MockAnthropicSse::textResponse('Listed the working directory.');
            },
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Where are we?', new HaoCodeConfig(
            allowedTools: ['Bash'],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'smart',
        )));

        $interrupts = array_filter($messages, fn (Message $message): bool => $message->isInterrupt());
        $this->assertCount(0, $interrupts, 'smart mode must not interrupt an allowlisted batch.');

        $autoDecisions = array_values(array_filter($messages, fn (Message $message): bool => $message->isAutoDecision()));
        $this->assertCount(1, $autoDecisions);
        $this->assertSame('approve', $autoDecisions[0]->decision);
        $this->assertSame('rule', $autoDecisions[0]->source);
        $this->assertSame('low', $autoDecisions[0]->riskLevel);
        $this->assertSame('Bash', $autoDecisions[0]->toolName);
        $this->assertSame('smart-pwd', $autoDecisions[0]->actionId);

        $results = array_values(array_filter($messages, fn (Message $message): bool => $message->isResult()));
        $this->assertCount(1, $results);
        $this->assertSame('Listed the working directory.', $results[0]->text);
    }

    public function test_smart_mode_red_line_batch_escalates_with_events_then_interrupts(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'smart-safe', 'name' => 'Bash', 'input' => ['command' => 'pwd']],
                ['id' => 'smart-red', 'name' => 'Bash', 'input' => ['command' => 'sudo ls']],
            ]),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Run both commands', new HaoCodeConfig(
            allowedTools: ['Bash'],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'smart',
        )));

        $interrupts = array_values(array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
        $this->assertCount(1, $interrupts, 'red-line batch must still interrupt for a human.');
        $this->assertCount(0, array_filter($messages, fn (Message $message): bool => $message->isResult()));

        $autoDecisions = array_values(array_filter($messages, fn (Message $message): bool => $message->isAutoDecision()));
        $this->assertCount(2, $autoDecisions);
        foreach ($autoDecisions as $event) {
            $this->assertSame('escalate', $event->decision);
        }
        // The allowlisted sibling is collateral; the red line carries the rule reason.
        $this->assertStringStartsWith('batch:escalated', (string) $autoDecisions[0]->reason);
        $this->assertSame('smart-safe', $autoDecisions[0]->actionId);
        $this->assertStringStartsWith('rule:red_line:', (string) $autoDecisions[1]->reason);
        $this->assertSame('high', $autoDecisions[1]->riskLevel);
        $this->assertSame('smart-red', $autoDecisions[1]->actionId);

        // Escalation events must precede the interrupt message.
        $positions = array_map(
            static fn (Message $message): string => $message->type,
            $messages,
        );
        $this->assertLessThan(
            array_search('interrupt', $positions, true),
            array_search('auto_decision', $positions, true),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  auto mode
    // ──────────────────────────────────────────────────────────────

    public function test_auto_mode_suppresses_tool_interrupts_and_executes(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('auto-write', 'Write', [
                'file_path' => 'auto.txt',
                'content' => 'auto-approved',
            ]),
            MockAnthropicSse::textResponse('Write completed without a human.'),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Write the file', new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'auto',
        )));

        $this->assertCount(0, array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
        $this->assertFileExists($this->projectDir.'/auto.txt');
        $this->assertSame('auto-approved', file_get_contents($this->projectDir.'/auto.txt'));

        $autoDecisions = array_values(array_filter($messages, fn (Message $message): bool => $message->isAutoDecision()));
        $this->assertCount(1, $autoDecisions);
        $this->assertSame('approve', $autoDecisions[0]->decision);
        $this->assertSame('rule', $autoDecisions[0]->source);
        $this->assertStringContainsString('auto', (string) $autoDecisions[0]->reason);

        $results = array_values(array_filter($messages, fn (Message $message): bool => $message->isResult()));
        $this->assertCount(1, $results);
        $this->assertSame('Write completed without a human.', $results[0]->text);
    }

    public function test_auto_mode_still_interrupts_ask_user_question(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('ask-1', 'AskUserQuestion', [
                'questions' => [[
                    'question' => 'Choose environment',
                    'type' => 'multiple_choice',
                    'options' => ['staging', 'production'],
                    'required' => true,
                ]],
            ]),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Ask me first', new HaoCodeConfig(
            ephemeral: false,
            enableAskUser: true,
            hitlMode: 'auto',
        )));

        $interrupts = array_values(array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
        $this->assertCount(1, $interrupts, 'AskUserQuestion must still interrupt in auto mode.');
        $this->assertSame(['respond', 'reject'], $interrupts[0]->interrupt->actions[0]->allowedDecisions);

        $autoDecisions = array_values(array_filter($messages, fn (Message $message): bool => $message->isAutoDecision()));
        $this->assertCount(1, $autoDecisions);
        $this->assertSame('escalate', $autoDecisions[0]->decision);
        $this->assertStringStartsWith('rule:ask:', (string) $autoDecisions[0]->reason);
    }

    // ──────────────────────────────────────────────────────────────
    //  ask mode regression (default): no auto_decision events at all
    // ──────────────────────────────────────────────────────────────

    public function test_ask_mode_emits_no_auto_decision_events(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('ask-mode-bash', 'Bash', [
                'command' => 'pwd',
                'description' => 'Guarded command',
            ]),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Run it', new HaoCodeConfig(
            allowedTools: ['Bash'],
            ephemeral: false,
            interruptOn: ['Bash' => true],
        )));

        $this->assertCount(0, array_filter($messages, fn (Message $message): bool => $message->isAutoDecision()));
        $this->assertCount(1, array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers (mirrors SdkE2ETest infrastructure)
    // ──────────────────────────────────────────────────────────────

    private function bootWithMock(array $responses): void
    {
        $requests = [];
        $this->refreshApplication();
        $this->app->useStoragePath($this->storageDir);

        $_SERVER['HAOCODE_STORAGE_PATH'] = $this->storageDir;
        putenv('HAOCODE_STORAGE_PATH='.$this->storageDir);

        config([
            'haocode.api_key' => 'test-key',
            'haocode.api_base_url' => 'https://mock.anthropic.test',
            'haocode.model' => 'claude-test',
            'haocode.max_tokens' => 4096,
            'haocode.permission_mode' => 'bypass_permissions',
            'haocode.global_settings_path' => $this->homeDir.'/.haocode/settings.json',
            'haocode.session_path' => $this->sessionDir,
            'haocode.api_stream_idle_timeout' => 2,
            'haocode.api_stream_poll_timeout' => 0.01,
        ]);

        $this->app->singleton(StreamingClient::class, function ($app) use (&$requests, $responses): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: 'claude-test',
                baseUrl: 'https://mock.anthropic.test',
                maxTokens: 4096,
                httpClient: MockAnthropicSse::client($responses, $requests),
                settingsManager: $app->make(SettingsManager::class),
                idleTimeoutSeconds: 2,
                streamPollTimeoutSeconds: 0.01,
            );
        });
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
}

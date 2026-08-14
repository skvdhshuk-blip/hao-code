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

trait SdkE2ETestTestStreamEmitsInterruptWithoutFakeResultConcern
{

    public function test_stream_emits_interrupt_without_fake_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_hitl_bash', 'Bash', [
                'command' => 'echo guarded',
                'description' => 'Guarded command',
            ]),
        ]);
        chdir($this->projectDir);

        $messages = iterator_to_array(HaoCode::stream('Run it', new HaoCodeConfig(
            allowedTools: ['Bash'],
            ephemeral: false,
            interruptOn: ['Bash' => true],
            hitlMode: 'ask',
        )));

        $this->assertCount(1, array_filter($messages, fn (Message $message): bool => $message->isInterrupt()));
        $this->assertCount(0, array_filter($messages, fn (Message $message): bool => $message->isResult()));
    }

    public function test_stream_interrupt_can_resume_to_one_real_result(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('stream-write', 'Write', [
                'file_path' => 'stream-resume.txt',
                'content' => 'ok',
            ]),
            MockAnthropicSse::textResponse('Stream resume completed.'),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;

        $resumed = iterator_to_array(HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('stream-write')],
            $config,
        ));
        $results = array_values(array_filter($resumed, fn (Message $message): bool => $message->isResult()));

        $this->assertCount(1, $results);
        $this->assertSame('Stream resume completed.', $results[0]->text);
        $this->assertSame(32, $results[0]->usage['last_turn_input_tokens']);
    }

    public function test_stream_resume_closes_sandbox_before_terminal_result_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('terminal-stream-write', 'Write', [
                'file_path' => 'terminal-stream.txt',
                'content' => 'ok',
            ]),
            MockAnthropicSse::textResponse('Terminal stream resume completed.'),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('terminal-stream-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $result = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isResult()) {
                    $result = $resumed->current();
                    break;
                }
                $resumed->next();
            }
            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame('Terminal stream resume completed.', $result->text);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_stream_resume_closes_sandbox_before_terminal_error_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('terminal-error-write', 'Write', [
                'file_path' => 'terminal-error.txt',
                'content' => 'ok',
            ]),
            new MockResponse(
                '{"error":{"type":"invalid_request_error","message":"resume failed"}}',
                ['http_code' => 400],
            ),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the file', $config));
        $interrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($interrupt->sessionId, $interrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('terminal-error-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $error = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isError()) {
                    $error = $resumed->current();
                    break;
                }
                $resumed->next();
            }
            $this->assertInstanceOf(Message::class, $error);
            $this->assertSame('resume failed', $error->error);
            $this->assertDirectoryDoesNotExist($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_stream_resume_preserves_sandbox_before_a_second_interrupt_is_yielded(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('first-stream-write', 'Write', [
                'file_path' => 'first-stream.txt',
                'content' => 'first',
            ]),
            MockAnthropicSse::toolUseResponse('second-stream-write', 'Write', [
                'file_path' => 'second-stream.txt',
                'content' => 'second',
            ]),
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Write'],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
            sandbox: SandboxConfig::local(cleanup: 'always'),
        );
        $initial = iterator_to_array(HaoCode::stream('Write the first file', $config));
        $firstInterrupt = array_values(array_filter(
            $initial,
            static fn (Message $message): bool => $message->isInterrupt(),
        ))[0]->interrupt;
        $state = \HaoCode\Support\Runtime\SdkRuntime::app(
            \HaoCode\Services\Session\SessionManager::class,
        )->getInterruptState($firstInterrupt->sessionId, $firstInterrupt->id);
        $sandboxRoot = $state['checkpoint']['run_snapshot']['sandbox_lease']['identity']['root']
            ?? $state['checkpoint']['run_snapshot']['sandbox_lease']['root']
            ?? null;
        $this->assertIsString($sandboxRoot);

        $resumed = HaoCode::streamResumeInterrupt(
            $firstInterrupt->sessionId,
            $firstInterrupt->id,
            [HumanDecision::approve('first-stream-write')],
            $config,
        );
        try {
            $resumed->rewind();
            $secondInterrupt = null;
            while ($resumed->valid()) {
                if ($resumed->current()->isInterrupt()) {
                    $secondInterrupt = $resumed->current();
                    break;
                }
                $resumed->next();
            }

            $this->assertInstanceOf(Message::class, $secondInterrupt);
            $this->assertSame('second-stream-write', $secondInterrupt->interrupt?->actions[0]->id);
            $this->assertDirectoryExists($sandboxRoot);
        } finally {
            unset($resumed);
            gc_collect_cycles();
            if (is_dir($sandboxRoot)) {
                $this->removeDirectory($sandboxRoot);
            }
        }
    }

    public function test_multi_tool_interrupt_executes_unguarded_sibling_once_and_checkpoints_its_result(): void
    {
        $executions = 0;
        $lookup = new class($executions) extends SdkTool {
            public function __construct(private int &$executions) {}
            public function name(): string { return 'Lookup'; }
            public function description(): string { return 'Return a stable lookup value.'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { $this->executions++; return 'lookup-value'; }
            public function isReadOnly(array $input): bool { return true; }
        };
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'lookup-1', 'name' => 'Lookup', 'input' => []],
                ['id' => 'write-1', 'name' => 'Write', 'input' => ['file_path' => 'batch.txt', 'content' => 'batch']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('lookup-value', $results);
                $this->assertStringContainsString('batch.txt', $results);
                return MockAnthropicSse::textResponse('Batch resumed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Lookup', 'Write'],
            tools: [$lookup],
            ephemeral: false,
            interruptOn: ['Write' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Lookup then write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }
        $this->assertSame(1, $executions);
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('write-1')],
            $config,
        );
        $this->assertSame(1, $executions);
        $this->assertSame('Batch resumed.', $result->text);
    }

    public function test_hitl_resume_promotes_prior_read_receipts_only_after_interrupted_batch_results_are_visible(): void
    {
        file_put_contents($this->projectDir.'/target.txt', 'old-content');
        $makeRevisionWriteTool = static fn (string $name): SdkTool => new class($name) extends SdkTool {
            public function __construct(private readonly string $toolName) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'Write a file after checking its read receipt.'; }
            public function parameters(): array
            {
                return [
                    'file_path' => ['type' => 'string', 'required' => true],
                    'content' => ['type' => 'string', 'required' => true],
                ];
            }
            public function handle(array $input): string { return 'unused'; }
            public function isReadOnly(array $input): bool { return $this->toolName === 'ReceiptWrite'; }
            public function call(array $input, \HaoCode\Tools\ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                $path = (string) $input['file_path'];
                if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
                    $path = rtrim($context->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
                }
                $content = (string) $input['content'];
                if (file_exists($path) && ($error = $context->fileRevisionError($path)) !== null) {
                    return \HaoCode\Tools\ToolResult::error($error);
                }
                file_put_contents($path, $content);
                $context->recordFileRead($path, $content);

                return \HaoCode\Tools\ToolResult::success($this->toolName.' wrote '.$path);
            }
        };
        $sensitiveWrite = $makeRevisionWriteTool('SensitiveWrite');
        $receiptWrite = $makeRevisionWriteTool('ReceiptWrite');
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'read-before-hitl', 'name' => 'Read', 'input' => ['file_path' => 'target.txt']],
                ['id' => 'sensitive-write', 'name' => 'SensitiveWrite', 'input' => ['file_path' => 'target.txt', 'content' => 'new-content']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('old-content', $results);
                $this->assertStringContainsString('Read tool first', $results);

                return MockAnthropicSse::toolUseResponse('write-after-visible-read', 'ReceiptWrite', [
                    'file_path' => 'target.txt',
                    'content' => 'new-content',
                ], 'msg_write_after_visible_read');
            },
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('ReceiptWrite wrote', $results);

                return MockAnthropicSse::textResponse('Visible read retry completed.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'SensitiveWrite', 'ReceiptWrite'],
            tools: [$sensitiveWrite, $receiptWrite],
            ephemeral: false,
            interruptOn: ['SensitiveWrite' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Read then request a sensitive write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $this->assertSame('old-content', file_get_contents($this->projectDir.'/target.txt'));
        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('sensitive-write')],
            $config,
        );

        $this->assertSame('Visible read retry completed.', $result->text);
        $this->assertSame('new-content', file_get_contents($this->projectDir.'/target.txt'));
    }

    public function test_hitl_resume_compacts_large_prior_read_results_before_promoting_receipts(): void
    {
        $largeContent = str_repeat('A', 130_000);
        file_put_contents($this->projectDir.'/target.txt', $largeContent);
        $makeRevisionWriteTool = static fn (string $name): SdkTool => new class($name) extends SdkTool {
            public function __construct(private readonly string $toolName) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'Write a file after checking its read receipt.'; }
            public function parameters(): array
            {
                return [
                    'file_path' => ['type' => 'string', 'required' => true],
                    'content' => ['type' => 'string', 'required' => true],
                ];
            }
            public function handle(array $input): string { return 'unused'; }
            public function isReadOnly(array $input): bool { return $this->toolName === 'ReceiptWrite'; }
            public function call(array $input, \HaoCode\Tools\ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                $path = (string) $input['file_path'];
                if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
                    $path = rtrim($context->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
                }
                if (file_exists($path) && ($error = $context->fileRevisionError($path)) !== null) {
                    return \HaoCode\Tools\ToolResult::error($error);
                }
                $content = (string) $input['content'];
                file_put_contents($path, $content);
                $context->recordFileRead($path, $content);

                return \HaoCode\Tools\ToolResult::success($this->toolName.' wrote '.$path);
            }
        };
        $sensitiveWrite = $makeRevisionWriteTool('SensitiveWrite');
        $receiptWrite = $makeRevisionWriteTool('ReceiptWrite');
        $this->bootWithMock([
            MockAnthropicSse::multiToolUseResponse([
                ['id' => 'large-read-before-hitl', 'name' => 'Read', 'input' => ['file_path' => 'target.txt']],
                ['id' => 'sensitive-write', 'name' => 'SensitiveWrite', 'input' => ['file_path' => 'target.txt', 'content' => 'new-content']],
            ]),
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('<persisted-output>', $results);
                $this->assertStringContainsString('Read tool first', $results);

                return MockAnthropicSse::toolUseResponse('write-after-compacted-read', 'ReceiptWrite', [
                    'file_path' => 'target.txt',
                    'content' => 'new-content',
                ], 'msg_write_after_compacted_read');
            },
            function (array $payload): MockResponse {
                $results = (string) MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Read the complete file first', $results);

                return MockAnthropicSse::textResponse('Compacted read retry stayed blocked.');
            },
        ]);
        chdir($this->projectDir);
        $config = new HaoCodeConfig(
            allowedTools: ['Read', 'SensitiveWrite', 'ReceiptWrite'],
            tools: [$sensitiveWrite, $receiptWrite],
            ephemeral: false,
            interruptOn: ['SensitiveWrite' => true],
            hitlMode: 'ask',
        );

        try {
            HaoCode::query('Read a large file then request a sensitive write', $config);
            $this->fail('Expected interrupt.');
        } catch (HumanInterruptException $e) {
            $interrupt = $e->interrupt;
        }

        $result = HaoCode::resumeInterrupt(
            $interrupt->sessionId,
            $interrupt->id,
            [HumanDecision::approve('sensitive-write')],
            $config,
        );

        $this->assertSame('Compacted read retry stayed blocked.', $result->text);
        $this->assertSame($largeContent, file_get_contents($this->projectDir.'/target.txt'));
    }
}

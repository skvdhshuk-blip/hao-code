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

trait SdkE2ETestTestMaxBudgetUsdWiresToCostTrackerConcern
{

    public function test_max_budget_usd_wires_to_cost_tracker(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Budget test.'),
        ]);

        chdir($this->projectDir);

        $config = new HaoCodeConfig(
            model: 'claude-sonnet-4-6',
            maxBudgetUsd: 2.50,
        );

        // Use reflection to verify CostTracker thresholds were set
        $method = new \ReflectionMethod(HaoCode::class, 'createRun');
        $run = $method->invoke(null, $config);
        $loop = $run->loop;

        $tracker = $loop->getCostTracker();
        $this->assertSame(2.50, $tracker->getStopThreshold());
        $run->close();
    }

    public function test_zero_budget_stops_before_sending_a_request(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('A zero budget must stop before an API request.');
            },
        ], model: 'claude-sonnet-4-6');
        chdir($this->projectDir);

        $result = HaoCode::query('Do not sample', new HaoCodeConfig(
            maxBudgetUsd: 0.0,
        ));

        $this->assertStringContainsString('Cost limit reached', $result->text);
        $this->assertSame(0.0, $result->cost);
        $this->assertSame(0, $result->turnsUsed);
    }

    public function test_max_budget_rejects_a_model_without_trusted_pricing(): void
    {
        $this->bootWithMock([
            function (): MockResponse {
                $this->fail('No request should be sent without trusted pricing.');
            },
        ]);
        chdir($this->projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No trusted pricing is configured');

        HaoCode::query('Budgeted request', new HaoCodeConfig(maxBudgetUsd: 1.0));
    }

    public function test_system_prompt_override_reaches_model_without_mutating_global_settings(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'You are a pirate. Always say "Arrr!".',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Custom prompt response.');
            },
        ]);

        chdir($this->projectDir);

        HaoCode::query('Test', new HaoCodeConfig(
            systemPrompt: 'You are a pirate. Always say "Arrr!".',
        ));

        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getSystemPrompt());
    }

    public function test_append_system_prompt_reaches_model_without_mutating_global_settings(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'Always respond in JSON format.',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Appended response.');
            },
        ]);

        chdir($this->projectDir);

        HaoCode::query('Test', new HaoCodeConfig(
            appendSystemPrompt: 'Always respond in JSON format.',
        ));

        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getAppendSystemPrompt());
    }

    public function test_sdk_tool_error_is_returned_to_model(): void
    {
        $failingTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'FailTool';
            }

            public function description(): string
            {
                return 'A tool that always fails.';
            }

            public function parameters(): array
            {
                return ['input' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                throw new \RuntimeException('Database connection refused');
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_fail', 'FailTool', [
                'input' => 'test',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Database connection refused', $toolResult);

                return MockAnthropicSse::textResponse('The tool failed with a database error.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Try the failing tool', new HaoCodeConfig(
            allowedTools: ['FailTool'],
            tools: [$failingTool],
        ));

        $this->assertStringContainsString('database error', $result->text);
    }

    public function test_custom_and_builtin_tools_work_together(): void
    {
        $dbTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'QueryDB';
            }

            public function description(): string
            {
                return 'Query the database.';
            }

            public function parameters(): array
            {
                return ['sql' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                return json_encode([
                    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
                    ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
                ]);
            }
        };

        $this->bootWithMock([
            // Turn 1: AI calls custom QueryDB tool
            MockAnthropicSse::toolUseResponse('toolu_db', 'QueryDB', [
                'sql' => 'SELECT * FROM users',
            ]),
            // Turn 2: AI writes query results to file using built-in Write
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Alice', $toolResult);
                $this->assertStringContainsString('Bob', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_write', 'Write', [
                    'file_path' => 'users.json',
                    'content' => $toolResult,
                ]);
            },
            // Turn 3: Summarize
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Exported 2 users to users.json.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Export all users to a file', new HaoCodeConfig(
            allowedTools: ['Write', 'QueryDB'],
            permissionMode: 'bypass_permissions',
            tools: [$dbTool],
        ));

        $this->assertStringContainsString('2 users', $result->text);
        $this->assertFileExists($this->projectDir.'/users.json');

        $users = json_decode(file_get_contents($this->projectDir.'/users.json'), true);
        $this->assertCount(2, $users);
        $this->assertSame('Alice', $users[0]['name']);
    }

    public function test_stream_multi_turn_collects_all_event_types(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_g1', 'Glob', [
                'pattern' => '*.txt',
            ]),
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                    'command' => 'echo "found files"',
                    'description' => 'List results',
                ]);
            },
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Found files and listed them.');
            },
        ]);

        chdir($this->projectDir);

        $typeCounter = [];
        foreach (HaoCode::stream('Find all text files', new HaoCodeConfig(
            allowedTools: ['Glob', 'Bash'],
            permissionMode: 'bypass_permissions',
        )) as $msg) {
            $typeCounter[$msg->type] = ($typeCounter[$msg->type] ?? 0) + 1;
        }

        // Should have tool_start (2x), tool_result (2x), text (1x), result (1x)
        $this->assertArrayHasKey('tool_start', $typeCounter);
        $this->assertArrayHasKey('tool_result', $typeCounter);
        $this->assertArrayHasKey('text', $typeCounter);
        $this->assertArrayHasKey('result', $typeCounter);
        $this->assertSame(2, $typeCounter['tool_start']);
        $this->assertSame(2, $typeCounter['tool_result']);
        $this->assertSame(1, $typeCounter['result']);
    }

    public function test_conversation_custom_tool_persists_across_turns(): void
    {
        $statefulTool = new class extends SdkTool
        {
            private array $items = [];

            public function name(): string
            {
                return 'CartAdd';
            }

            public function description(): string
            {
                return 'Add item to cart.';
            }

            public function parameters(): array
            {
                return ['item' => ['type' => 'string', 'required' => true]];
            }

            public function handle(array $input): string
            {
                $this->items[] = $input['item'];

                return 'Cart: '.implode(', ', $this->items).' ('.count($this->items).' items)';
            }

            // Stateful tools must NOT be read-only, otherwise they get
            // fork-executed and state changes are lost in the child process.
            public function isReadOnly(array $input): bool
            {
                return false;
            }
        };

        $this->bootWithMock([
            // Turn 1: Add apple
            MockAnthropicSse::toolUseResponse('toolu_c1', 'CartAdd', ['item' => 'apple']),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('apple', $toolResult);
                $this->assertStringContainsString('1 items', $toolResult);

                return MockAnthropicSse::textResponse('Added apple to cart.');
            },
            // Turn 2: Add banana — tool should remember apple from turn 1
            MockAnthropicSse::toolUseResponse('toolu_c2', 'CartAdd', ['item' => 'banana']),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('apple', $toolResult);
                $this->assertStringContainsString('banana', $toolResult);
                $this->assertStringContainsString('2 items', $toolResult);

                return MockAnthropicSse::textResponse('Cart now has apple and banana.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['CartAdd'],
            permissionMode: 'bypass_permissions',
            tools: [$statefulTool],
        ));

        $r1 = $conv->send('Add apple to my cart');
        $this->assertStringContainsString('apple', $r1->text);

        $r2 = $conv->send('Now add banana');
        $this->assertStringContainsString('banana', $r2->text);
        $this->assertSame(2, $conv->getTurnCount());

        $conv->close();
    }

    public function test_query_result_works_in_string_operations(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('The answer is 42.'),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('What is the answer?');

        // String concatenation
        $this->assertSame('Result: The answer is 42.', 'Result: '.$result);

        // str_contains
        $this->assertTrue(str_contains((string) $result, '42'));

        // strlen
        $this->assertSame(strlen('The answer is 42.'), strlen((string) $result));

        // json_encode wraps in quotes
        $this->assertStringContainsString('42', json_encode($result->text));
    }

    public function test_multiple_sdk_tools_registered_simultaneously(): void
    {
        $toolA = new class extends SdkTool
        {
            public function name(): string
            {
                return 'ToolAlpha';
            }

            public function description(): string
            {
                return 'First tool.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'alpha-result';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $toolB = new class extends SdkTool
        {
            public function name(): string
            {
                return 'ToolBeta';
            }

            public function description(): string
            {
                return 'Second tool.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'beta-result';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_a', 'ToolAlpha', []),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('alpha-result', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_b', 'ToolBeta', []);
            },
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('beta-result', $toolResult);

                return MockAnthropicSse::textResponse('Both tools executed.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Use both tools', new HaoCodeConfig(
            allowedTools: ['ToolAlpha', 'ToolBeta'],
            tools: [$toolA, $toolB],
        ));

        $this->assertStringContainsString('Both tools', $result->text);
    }
}

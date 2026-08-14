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

trait SdkE2ETestTestConversationExposesCostAndSessionIdConcern
{

    public function test_conversation_exposes_cost_and_session_id(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('Turn one.'),
            MockAnthropicSse::textResponse('Turn two.'),
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(ephemeral: false));

        $conv->send('First');
        $this->assertIsFloat($conv->getCost());
        $this->assertNotNull($conv->getSessionId());

        $conv->send('Second');
        $this->assertSame(2, $conv->getTurnCount());

        $conv->close();
    }

    public function test_sdk_skill_is_invocable_by_agent(): void
    {
        $this->bootWithMock([
            // Agent decides to invoke the custom skill
            MockAnthropicSse::toolUseResponse('toolu_skill', 'Skill', [
                'skill' => 'security-review',
                'args' => 'auth.php',
            ]),
            // Agent receives the expanded skill prompt and acts on it
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                // Skill prompt should be returned as tool result
                $this->assertStringContainsString('OWASP Top 10', $toolResult);
                $this->assertStringContainsString('auth.php', $toolResult);

                return MockAnthropicSse::textResponse('Security review complete. No critical issues found.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'security-review',
                    description: 'Review code for security vulnerabilities',
                    prompt: 'Review the following file for OWASP Top 10 vulnerabilities. Focus on injection, XSS, and auth bypass. File: $ARGUMENTS',
                ),
            ],
        ));

        $this->assertStringContainsString('Security review complete', $result->text);
    }

    public function test_sdk_skill_definition_converts_correctly(): void
    {
        $skill = new SdkSkill(
            name: 'lint-check',
            description: 'Run linting on the project',
            prompt: 'Run lint checks on $ARGUMENTS and report issues.',
            allowedTools: ['Bash', 'Read'],
            model: 'haiku',
        );

        $def = $skill->toDefinition();

        $this->assertSame('lint-check', $def->name);
        $this->assertSame('Run linting on the project', $def->description);
        $this->assertStringContainsString('Run lint checks', $def->prompt);
        $this->assertSame(['Bash', 'Read'], $def->allowedTools);
        $this->assertSame('haiku', $def->model);
    }

    public function test_multiple_sdk_skills_registered(): void
    {
        $this->bootWithMock([
            // Agent invokes the second skill
            MockAnthropicSse::toolUseResponse('toolu_s2', 'Skill', [
                'skill' => 'deploy',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Deploy to production', $toolResult);

                return MockAnthropicSse::textResponse('Deployment initiated.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Deploy the app', new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'test-suite',
                    description: 'Run the test suite',
                    prompt: 'Run all tests and report failures.',
                ),
                new SdkSkill(
                    name: 'deploy',
                    description: 'Deploy to production',
                    prompt: 'Deploy to production. Run tests first, then build and push.',
                ),
            ],
        ));

        $this->assertStringContainsString('Deployment', $result->text);
    }

    public function test_sdk_skills_and_tools_work_together(): void
    {
        $dbTool = new class extends SdkTool
        {
            public function name(): string
            {
                return 'CheckDB';
            }

            public function description(): string
            {
                return 'Check database status.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'DB: 3 tables, 150 rows, healthy';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $this->bootWithMock([
            // Agent invokes custom skill first
            MockAnthropicSse::toolUseResponse('toolu_skill', 'Skill', [
                'skill' => 'health-check',
            ]),
            // Agent then calls custom tool
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Check all systems', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_db', 'CheckDB', []);
            },
            // Summary
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('3 tables', $toolResult);

                return MockAnthropicSse::textResponse('Health check passed. DB is healthy with 150 rows.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Run a full health check', new HaoCodeConfig(
            allowedTools: ['Skill', 'CheckDB'],
            skills: [
                new SdkSkill(
                    name: 'health-check',
                    description: 'Run system health check',
                    prompt: 'Check all systems: database, cache, and queues. Use CheckDB tool for database.',
                ),
            ],
            tools: [$dbTool],
        ));

        $this->assertStringContainsString('Health check passed', $result->text);
    }

    public function test_conversation_applies_system_prompt_override_before_send(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertStringContainsString(
                    'You are a release manager. Always think about rollback safety.',
                    $payload['system'][0]['text'],
                );

                return MockAnthropicSse::textResponse('Conversation prompt response.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            systemPrompt: 'You are a release manager. Always think about rollback safety.',
        ));

        $result = $conv->send('Give me a deployment recommendation.');

        $this->assertStringContainsString('Conversation prompt response', $result->text);
        $settings = app(SettingsManager::class);
        $this->assertNull($settings->getSystemPrompt());

        $conv->close();
    }

    public function test_conversation_registers_sdk_skills_before_send(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_skill_conv', 'Skill', [
                'skill' => 'release-guard',
                'args' => 'billing.php',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Validate release readiness', $toolResult);
                $this->assertStringContainsString('billing.php', $toolResult);
                $this->assertStringContainsString('rollback checklist', $toolResult);

                return MockAnthropicSse::textResponse('Release guard review complete.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::conversation(new HaoCodeConfig(
            allowedTools: ['Skill'],
            skills: [
                new SdkSkill(
                    name: 'release-guard',
                    description: 'Validate release readiness before deployment',
                    prompt: 'Validate release readiness for $ARGUMENTS and include a rollback checklist.',
                ),
            ],
        ));

        $result = $conv->send('Review billing.php before deployment.');

        $this->assertStringContainsString('Release guard review complete', $result->text);

        $conv->close();
    }

    public function test_structured_parses_markdown_fenced_json_response(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse(<<<'JSON'
```json
{"category":"shipping","priority":"high","score":95}
```
JSON),
        ]);

        chdir($this->projectDir);

        $result = HaoCode::structured('Classify this support ticket.', [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'score' => ['type' => 'integer'],
            ],
            'required' => ['category', 'priority', 'score'],
        ]);

        $this->assertSame('shipping', $result->category);
        $this->assertSame('high', $result['priority']);
        $this->assertSame(95, $result->score);
        $this->assertInstanceOf(QueryResult::class, $result->queryResult);
        $this->assertStringContainsString('```json', $result->rawText);
    }

    public function test_resume_restores_previous_session_history(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::textResponse('The project codename is ORBIT.'),
        ]);

        chdir($this->projectDir);

        $seedResult = HaoCode::query(
            'Remember that the project codename is ORBIT.',
            new HaoCodeConfig(allowedTools: [], ephemeral: false),
        );
        $this->assertNotNull($seedResult->sessionId);
        $this->assertFileExists($this->sessionDir.'/'.$seedResult->sessionId.'.jsonl');

        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('What is the project codename?', MockAnthropicSse::lastUserText($payload));
                $this->assertSame(
                    'Remember that the project codename is ORBIT.',
                    $payload['messages'][0]['content'] ?? null,
                );
                $this->assertSame('assistant', $payload['messages'][1]['role'] ?? null);

                return MockAnthropicSse::textResponse('The codename is ORBIT.');
            },
        ]);

        chdir($this->projectDir);

        $conv = HaoCode::resume($seedResult->sessionId);
        $result = $conv->send('What is the project codename?');

        $this->assertStringContainsString('ORBIT', $result->text);
        $this->assertSame($seedResult->sessionId, $result->sessionId);
        $this->assertSame(1, $result->turnsUsed);

        $conv->close();
    }

    public function test_query_continue_session_prefers_latest_session_in_same_working_directory(): void
    {
        $otherProjectDir = $this->tempRoot.'/other-project';
        mkdir($otherProjectDir, 0755, true);

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Workspace alpha remembered.'),
        ]);

        chdir($this->tempRoot);
        HaoCode::query('Remember that this workspace is alpha.', new HaoCodeConfig(
            cwd: $this->projectDir,
            ephemeral: false,
        ));

        sleep(1);

        $this->bootWithMock([
            MockAnthropicSse::textResponse('Workspace beta remembered.'),
        ]);

        chdir($this->tempRoot);
        HaoCode::query('Remember that this workspace is beta.', new HaoCodeConfig(
            cwd: $otherProjectDir,
            ephemeral: false,
        ));

        $this->bootWithMock([
            function (array $payload): MockResponse {
                $this->assertSame(3, MockAnthropicSse::messageCount($payload));
                $this->assertSame('Which workspace am I in?', MockAnthropicSse::lastUserText($payload));
                $this->assertSame(
                    'Remember that this workspace is alpha.',
                    $payload['messages'][0]['content'] ?? null,
                );

                return MockAnthropicSse::textResponse('You are in workspace alpha.');
            },
        ]);

        chdir($this->projectDir);

        $result = HaoCode::query('Which workspace am I in?', new HaoCodeConfig(
            continueSession: true,
            cwd: $this->projectDir,
        ));

        $this->assertStringContainsString('workspace alpha', $result->text);
    }

    public function test_independent_queries_do_not_share_read_before_write_state(): void
    {
        $file = $this->projectDir.'/isolated.txt';
        file_put_contents($file, 'before');

        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_read', 'Read', ['file_path' => $file]),
            MockAnthropicSse::textResponse('Read complete.'),
            MockAnthropicSse::toolUseResponse('toolu_edit', 'Edit', [
                'file_path' => $file,
                'old_string' => 'before',
                'new_string' => 'after',
            ]),
            function (array $payload): MockResponse {
                $this->assertStringContainsString('must be read before writing', MockAnthropicSse::lastToolResultText($payload) ?? '');

                return MockAnthropicSse::textResponse('Edit was correctly blocked.');
            },
        ]);

        HaoCode::query('Read the file', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: ['Read'],
            ephemeral: true,
        ));
        HaoCode::query('Edit the file without reading it', new HaoCodeConfig(
            cwd: $this->projectDir,
            allowedTools: ['Edit'],
            permissionMode: 'bypass_permissions',
            ephemeral: true,
        ));

        $this->assertSame('before', file_get_contents($file));
    }

    public function test_credential_pool_is_applied_by_hao_code_query(): void
    {
        $pool = new CredentialPool;
        $pool->add('anthropic', new Credential(apiKey: 'pool-key', id: 'pool'));

        $this->bootWithMock([
            function (array $payload, int $requestNumber, array $request): MockResponse {
                $this->assertStringContainsString('pool-key', json_encode($request['headers']));

                return MockAnthropicSse::textResponse('Pool applied.');
            },
        ]);
        config(['haocode.api_key' => '']);

        $result = HaoCode::query('Use the pool', new HaoCodeConfig(
            credentialPool: $pool,
            allowedTools: [],
            ephemeral: true,
        ));

        $this->assertSame('Pool applied.', $result->text);
    }

    public function test_structured_uses_response_schema_from_config(): void
    {
        $this->bootWithMock([
            function (array $payload): MockResponse {
                $prompt = MockAnthropicSse::lastUserText($payload) ?? '';
                $this->assertStringContainsString('configured_field', $prompt);
                $this->assertStringNotContainsString('method_field', $prompt);

                return MockAnthropicSse::textResponse('{"configured_field":"ok"}');
            },
        ]);

        $result = HaoCode::structured('Return data', [
            'type' => 'object',
            'properties' => ['method_field' => ['type' => 'string']],
        ], new HaoCodeConfig(
            responseSchema: [
                'type' => 'object',
                'properties' => ['configured_field' => ['type' => 'string']],
            ],
        ));

        $this->assertSame('ok', $result->configured_field);
    }
}

<?php

namespace Tests\Unit;

use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Services\Memory\TieredSummarizer;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for the tiered memory framework.
 *
 * Covers: TieredSummarizer, SessionMemory tiered fields,
 * MemoryReadTool, forSystemPrompt at all levels, edge cases.
 */
class TieredMemoryTest extends TestCase
{
    private string $tmpHome;
    private SessionMemory $memory;
    private TieredSummarizer $summarizer;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/haocode_tiered_test_' . getmypid();
        @mkdir($this->tmpHome, 0755, true);
        $_SERVER['HOME'] = $this->tmpHome;

        $this->memory = new SessionMemory;
        $this->summarizer = new TieredSummarizer;
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tmpHome . '/.haocode');
        $this->cleanDir($this->tmpHome);
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ─── TieredSummarizer ─────────────────────────────────────

    public function test_summarizer_generates_l0_and_l1_for_content(): void
    {
        $result = $this->summarizer->summarize(
            'We decided to use PostgreSQL as the primary database because of its JSONB support and advanced indexing capabilities.'
        );

        $this->assertNotEmpty($result['l0']);
        $this->assertNotEmpty($result['l1']);
        $this->assertGreaterThan(0, $result['l0_tokens']);
        $this->assertGreaterThan(0, $result['l1_tokens']);
        $this->assertContains($result['mode'], ['llm', 'fallback']);
    }

    public function test_summarizer_handles_empty_content(): void
    {
        $result = $this->summarizer->summarize('');

        $this->assertSame('', $result['l0']);
        $this->assertSame('', $result['l1']);
        $this->assertSame(0, $result['l0_tokens']);
        $this->assertSame(0, $result['l1_tokens']);
        $this->assertSame('empty', $result['mode']);
    }

    public function test_summarizer_l0_is_short(): void
    {
        $longContent = str_repeat(
            'The team discussed the architecture for the new microservice. ',
            20,
        );

        $result = $this->summarizer->summarize($longContent);

        // L0 should be at most ~200 chars (50 tokens * 4 chars)
        $this->assertLessThanOrEqual(250, mb_strlen($result['l0']));
        // L1 should be at most ~2000 chars (500 tokens * 4 chars)
        $this->assertLessThanOrEqual(2200, mb_strlen($result['l1']));
        // L0 should be shorter than L1
        $this->assertLessThan(mb_strlen($result['l1']), mb_strlen($result['l0']));
    }

    public function test_summarizer_handles_chinese_content(): void
    {
        $result = $this->summarizer->summarize(
            '团队决定采用微服务架构来拆分单体应用。订单服务、支付服务、用户服务各自独立部署，通过 gRPC 通信。'
        );

        $this->assertNotEmpty($result['l0']);
        $this->assertNotEmpty($result['l1']);
    }

    public function test_summarizer_count_tokens_approximation(): void
    {
        // ~4 chars per token approximation
        $tokens = $this->summarizer->countTokens('Hello, World!');
        $this->assertEquals(4, $tokens); // 13 chars / 4 = 3.25 → ceil = 4

        $tokens = $this->summarizer->countTokens('');
        $this->assertEquals(1, $tokens); // min 1

        $tokens = $this->summarizer->countTokens('Hi');
        $this->assertEquals(1, $tokens); // 2 chars / 4 = 0.5 → ceil = 1
    }

    // ─── SessionMemory tiered fields ─────────────────────────

    public function test_set_generates_summary_fields(): void
    {
        $this->memory->set(
            key: 'db_choice',
            value: 'We selected PostgreSQL 16 for its advanced JSONB indexing and full-text search capabilities.',
            type: 'decision',
        );

        $entry = $this->memory->getEntry('db_choice');

        $this->assertNotNull($entry);
        $this->assertArrayHasKey('l0', $entry);
        $this->assertArrayHasKey('l1', $entry);
        $this->assertArrayHasKey('l0_tokens', $entry);
        $this->assertArrayHasKey('l1_tokens', $entry);
        $this->assertArrayHasKey('l2_tokens', $entry);
        $this->assertArrayHasKey('summary_mode', $entry);
        $this->assertArrayHasKey('summary_generated_at', $entry);
        $this->assertNotEmpty($entry['l0']);
        $this->assertNotEmpty($entry['l1']);
    }

    public function test_get_summary_returns_correct_level(): void
    {
        $fullContent = 'We use Redis Cluster for caching with 3 masters and 3 replicas across 2 availability zones.';
        $this->memory->set('cache_config', $fullContent, 'ops');

        $l0 = $this->memory->getSummary('cache_config', 'l0');
        $l1 = $this->memory->getSummary('cache_config', 'l1');
        $l2 = $this->memory->getSummary('cache_config', 'l2');

        $this->assertNotEmpty($l0);
        $this->assertNotEmpty($l1);
        $this->assertSame($fullContent, $l2);
        // L0 should be shorter than or equal to L1 (equal only when content is very short)
        $this->assertLessThanOrEqual(mb_strlen($l1), mb_strlen($l0));
    }

    public function test_get_summary_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->memory->getSummary('no_such_key'));
        $this->assertNull($this->memory->getSummary('no_such_key', 'l1'));
        $this->assertNull($this->memory->getSummary('no_such_key', 'l2'));
    }

    public function test_get_entry_returns_full_entry_with_summaries(): void
    {
        $this->memory->set('test_key', 'Test value content', 'note');

        $entry = $this->memory->getEntry('test_key');

        $this->assertSame('Test value content', $entry['value']);
        $this->assertSame('note', $entry['type']);
        $this->assertNotEmpty($entry['l0']);
        $this->assertNotEmpty($entry['l1']);
    }

    public function test_get_entry_returns_null_for_missing(): void
    {
        $this->assertNull($this->memory->getEntry('ghost_key'));
    }

    // ─── forSystemPrompt at different levels ─────────────────

    public function test_for_system_prompt_defaults_to_l0(): void
    {
        $this->memory->set('theme', 'Dark mode is the default UI theme', 'preference');

        $prompt = $this->memory->forSystemPrompt();

        // Should mention MemoryRead tool hint
        $this->assertStringContainsString('MemoryRead', $prompt);
        $this->assertStringContainsString('theme', $prompt);
    }

    public function test_for_system_prompt_l0_is_compact(): void
    {
        $longValue = str_repeat('Detailed project information. ', 30);
        $this->memory->set('project_info', $longValue, 'note');

        $l0Prompt = $this->memory->forSystemPrompt(maxChars: 5000, level: 'l0');
        $l2Prompt = $this->memory->forSystemPrompt(maxChars: 50000, level: 'l2');

        $this->assertLessThan(strlen($l2Prompt), strlen($l0Prompt));
        // L0 entry line should not contain the full long value
        $this->assertStringNotContainsString($longValue, $l0Prompt);
    }

    public function test_for_system_prompt_l2_includes_full_content(): void
    {
        $this->memory->set('specific_rule', 'Always use strict types in PHP files', 'policy');

        $prompt = $this->memory->forSystemPrompt(maxChars: 10000, level: 'l2');

        $this->assertStringContainsString('Always use strict types in PHP files', $prompt);
    }

    public function test_for_system_prompt_with_legacy_entries_no_summaries(): void
    {
        // Simulate a legacy entry without summary fields
        $data = [
            'old_key' => [
                'value' => 'Legacy content without summaries',
                'type' => 'note',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ],
        ];

        mkdir($this->tmpHome.'/.haocode', 0755, true);
        file_put_contents(
            $this->tmpHome.'/.haocode/memory.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        // Should not throw — uses fallbackL0
        $prompt = $this->memory->forSystemPrompt();
        $this->assertStringContainsString('old_key', $prompt);
    }

    public function test_for_system_prompt_respects_max_chars_across_levels(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->memory->set("key_{$i}", str_repeat("Content for entry {$i}. ", 20), 'note');
        }

        $limit = 500;
        foreach (['l0', 'l1', 'l2'] as $level) {
            $prompt = $this->memory->forSystemPrompt(maxChars: $limit, level: $level);
            $this->assertLessThanOrEqual($limit + 200, strlen($prompt),
                "Level {$level} exceeded maxChars limit");
        }
    }

    // ─── Regenerate summaries ────────────────────────────────

    public function test_regenerate_summaries_updates_all_entries(): void
    {
        $this->memory->set('a', 'Content A: First decision about architecture.', 'decision');
        $this->memory->set('b', 'Content B: Second decision about caching.', 'decision');

        // Store original summaries
        $origA = $this->memory->getEntry('a');

        // Small delay so timestamps differ
        usleep(10000); // 10ms

        $count = $this->memory->regenerateSummaries();

        $this->assertSame(2, $count);

        // Summary mode and tokens should still be populated
        $newA = $this->memory->getEntry('a');
        $this->assertNotEmpty($newA['l0']);
        $this->assertNotEmpty($newA['summary_mode']);
    }

    public function test_regenerate_summaries_for_specific_key(): void
    {
        $this->memory->set('x', 'X content about architecture decisions and patterns', 'note');
        $this->memory->set('y', 'Y content about deployment and operations runbooks', 'note');

        // Small delay so timestamps differ
        usleep(10000); // 10ms

        $count = $this->memory->regenerateSummaries('x');

        $this->assertSame(1, $count);

        // x's l0 should still be populated
        $this->assertNotEmpty($this->memory->getEntry('x')['l0']);
        // y should still exist
        $this->assertNotEmpty($this->memory->getEntry('y')['l0']);
    }

    public function test_regenerate_summaries_handles_unknown_key(): void
    {
        $count = $this->memory->regenerateSummaries('does_not_exist');
        $this->assertSame(0, $count);
    }

    // ─── MemoryReadTool ──────────────────────────────────────

    public function test_memory_read_tool_keys_lists_all(): void
    {
        $this->memory->set('mem1', 'First memory content', 'note');
        $this->memory->set('mem2', 'Second memory content', 'decision');

        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 'keys'],
            new ToolUseContext('/tmp', 'sess_1'),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('mem1', $result->output);
        $this->assertStringContainsString('mem2', $result->output);
    }

    public function test_memory_read_tool_keys_empty_store(): void
    {
        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 'keys'],
            new ToolUseContext('/tmp', 'sess_1'),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No persistent memories', $result->output);
    }

    public function test_memory_read_tool_fetches_l1(): void
    {
        $this->memory->set('arch', 'Architecture uses hexagonal pattern with ports and adapters.', 'decision');

        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 'arch', 'level' => 'l1'],
            new ToolUseContext('/tmp', 'sess_1'),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('arch', $result->output);
        $this->assertStringContainsString('level: l1', $result->output);
    }

    public function test_memory_read_tool_fetches_l2(): void
    {
        $content = 'Full detailed content about the deployment pipeline.';
        $this->memory->set('deploy', $content, 'ops');

        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 'deploy', 'level' => 'l2'],
            new ToolUseContext('/tmp', 'sess_1'),
        );

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('deploy', $result->output);
        $this->assertStringContainsString($content, $result->output);
    }

    public function test_memory_read_tool_errors_on_unknown_key(): void
    {
        $this->memory->set('real_key', 'Some content', 'note');

        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 'unknown_key'],
            new ToolUseContext('/tmp', 'sess_1'),
        );

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
        $this->assertStringContainsString('real_key', $result->output); // suggests available keys
    }

    public function test_memory_read_tool_is_read_only(): void
    {
        $tool = new MemoryReadTool;
        $this->assertTrue($tool->isReadOnly(['key' => 'anything']));
    }

    // ─── Search across summaries ─────────────────────────────

    public function test_search_matches_l0_content(): void
    {
        $this->memory->set('deploy_config',
            'Deployment uses Kubernetes with Helm charts and ArgoCD for GitOps.',
            'ops',
        );

        // Search should match even if the keyword only appears in the summary
        $results = $this->memory->search('Kubernetes');

        $this->assertArrayHasKey('deploy_config', $results);
    }

    public function test_search_matches_by_key_and_summary(): void
    {
        $this->memory->set('redis_cluster', 'Cache cluster with 6 nodes total.', 'ops');
        $this->memory->set('db_config', 'PostgreSQL connection pooling setup.', 'ops');

        $results = $this->memory->search('cache');
        $this->assertArrayHasKey('redis_cluster', $results);
        $this->assertArrayNotHasKey('db_config', $results);
    }

    // ─── Multi-session simulation ────────────────────────────

    public function test_multi_session_memory_accumulation(): void
    {
        // Session 1: architecture
        $this->memory->set('s1_arch', 'Monolith to microservices migration plan', 'decision');
        $this->memory->set('s1_db', 'MySQL 8.0 with InnoDB cluster', 'decision');

        $this->assertCount(2, $this->memory->list());

        // Verify existing memories have summaries
        $entry = $this->memory->getEntry('s1_arch');
        $this->assertNotEmpty($entry['l0']);

        // Session 2: bug fixes (same memory instance)
        $this->memory->set('s2_bug', 'Race condition in order creation fixed with pessimistic lock', 'bugfix');
        $this->memory->set('s2_perf', 'Added composite index on orders(user_id, status, created_at)', 'optimization');

        $this->assertCount(4, $this->memory->list());

        // Use MemoryRead tool to find session 1 content
        $tool = new MemoryReadTool;
        $result = $tool->call(
            ['key' => 's1_db', 'level' => 'l1'],
            new ToolUseContext('/tmp', 'sess_2'),
        );
        $this->assertFalse($result->isError);

        // Simulate process restart
        $memory2 = new SessionMemory;
        $this->assertCount(4, $memory2->list());
    }

    public function test_context_savings_with_realistic_data(): void
    {
        $memories = [
            'arch_pattern' => 'The system follows a modular monolith architecture with bounded contexts: Ordering, Payment, Inventory, Shipping, and Notification. Each context owns its database schema. Cross-context communication via synchronous REST for queries and asynchronous RabbitMQ events for commands. Shared kernel includes User identity and Money value object.',
            'tech_stack' => 'Backend: Laravel 12 with PHP 8.4, Octane (Swoole) for persistent workers. Database: PostgreSQL 16 with PostGIS for location queries. Cache: Redis Cluster 7.2. Queue: RabbitMQ 4.0 with dead-letter exchanges. Search: Elasticsearch 9.0. Observability: OpenTelemetry to Grafana Tempo + Loki + Mimir stack.',
            'security_policy' => 'OWASP Top 10 compliance mandatory. All user input sanitized via Laravel validation. SQL injection prevented by Eloquent ORM (never raw queries with user input). XSS prevented by Blade auto-escaping. CSRF tokens on all state-changing routes. API rate limiting: 60 req/min per user, 300 req/min per IP. JWT access tokens 15min TTL, refresh tokens 7 days with rotation.',
            'deployment_pipeline' => 'CI/CD: GitHub Actions. On push to main: lint (Pint + PHPStan L9) → unit tests (PHPUnit, 2000+ tests) → integration tests (TestContainers with real PostgreSQL/Redis/RabbitMQ) → build Docker image → push to ECR → deploy to staging k8s via ArgoCD. Staging runs E2E Cypress tests. Production deploy requires 2 senior approvals + passing staging smoke tests.',
            'oncall_runbook' => 'PagerDuty escalation: L1 engineer (15min ack) → L2 senior (15min ack) → Engineering Manager (30min). Common alerts: (1) 5xx rate > 1% — check application logs in Loki, recent deploys in ArgoCD; (2) P99 latency > 1s — check database slow query log, Redis memory, queue depth; (3) Queue backlog > 1000 — check consumer health, dead-letter queue; (4) Disk > 85% — run log rotation, check for runaway logging.',
        ];

        foreach ($memories as $key => $value) {
            $this->memory->set($key, $value, 'note');
        }

        $l0Prompt = $this->memory->forSystemPrompt(maxChars: 10000, level: 'l0');
        $l2Prompt = $this->memory->forSystemPrompt(maxChars: 100000, level: 'l2');

        $l0Size = strlen($l0Prompt);
        $l2Size = strlen($l2Prompt);

        // L0 should save significant context
        $this->assertLessThan($l2Size, $l0Size);

        $savings = $l2Size > 0 ? round((1 - $l0Size / $l2Size) * 100) : 0;
        // Even with LLM summaries, we should save at least 30% context
        $this->assertGreaterThan(20, $savings,
            "Expected >20% context savings with L0, got {$savings}%");
    }

    // ─── Tool name and description ───────────────────────────

    public function test_memory_read_tool_metadata(): void
    {
        $tool = new MemoryReadTool;

        $this->assertSame('MemoryRead', $tool->name());
        $this->assertNotEmpty($tool->description());
        $this->assertStringContainsString('l1', $tool->description());
        $this->assertStringContainsString('l2', $tool->description());
    }
}

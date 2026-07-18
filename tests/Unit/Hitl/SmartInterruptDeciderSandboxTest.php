<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Services\Hitl\HitlAllowlist;
use HaoCode\Services\Hitl\HitlReviewer;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two codex-alignment fast paths in the smart HITL decider:
 *
 * - Sandbox containment (feature B): a gray Bash action that genuinely runs
 *   inside the configured sandbox (mode 'full' on an isolating provider) is
 *   auto-approved with source 'sandbox' instead of consuming a model review.
 * - User-saved allow rules (feature C): an exactly matching Bash command is
 *   approved before the rule classifier, overriding even red lines.
 */
class SmartInterruptDeciderSandboxTest extends TestCase
{
    private const PROVIDER_CONFIG = [
        'apiKey' => 'sk-test-key',
        'model' => 'test-model',
        'baseUrl' => null,
        'providerType' => 'anthropic',
    ];

    private const GRAY_COMMAND = 'php artisan migrate'; // not on the read-only allowlist
    private const RED_COMMAND = 'sudo ls'; // hard red-line command

    private string $cwd;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function allowlistFile(string ...$commands): string
    {
        $path = sys_get_temp_dir().'/haocode_allowlist_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'rules' => array_map(
                static fn (string $command): array => [
                    'command' => $command,
                    'addedAt' => '2025-01-01T00:00:00+00:00',
                    'source' => 'user',
                ],
                $commands,
            ),
        ]));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function denyingReviewer(): HitlReviewer
    {
        $runner = static fn (): StructuredResult => new StructuredResult([
            'risk_level' => 'medium',
            'user_authorization' => 'low',
            'outcome' => 'deny',
            'rationale' => 'Denied by the test guardian.',
        ]);

        return new HitlReviewer(self::PROVIDER_CONFIG, $this->cwd, $runner);
    }

    /** @param HumanActionRequest[] $actions */
    private function interrupt(array $actions): HumanInterrupt
    {
        return new HumanInterrupt(
            id: 'int_test_sandbox',
            sessionId: 'session-test',
            actions: $actions,
            createdAt: date('c'),
        );
    }

    private static function approvable(string $id, string $toolName, array $input): HumanActionRequest
    {
        return new HumanActionRequest($id, $toolName, $input, 'Review this action');
    }

    private function decider(
        ?SandboxConfig $sandbox = null,
        ?HitlAllowlist $allowlist = null,
        ?HitlReviewer $reviewer = null,
    ): SmartInterruptDecider {
        return new SmartInterruptDecider(
            mode: 'smart',
            reviewer: $reviewer,
            cwd: $this->cwd,
            fallbackSessionId: 'session-test',
            sandbox: $sandbox,
            allowlist: $allowlist,
        );
    }

    // ─── feature B: sandbox containment ─────────────────────────────────

    public function test_gray_bash_action_is_approved_inside_isolating_sandbox(): void
    {
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'native', mode: 'full'),
            reviewer: $this->denyingReviewer(), // must not be consulted
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        $this->assertSame('auto', $batch['status']);
        $this->assertCount(1, $batch['decisions']);
        $this->assertSame('approve', $batch['decisions'][0]->type);
        $this->assertCount(1, $batch['events']);
        $event = $batch['events'][0];
        $this->assertSame('approve', $event->decision);
        $this->assertSame('sandbox', $event->source);
        $this->assertSame('low', $event->riskLevel);
        $this->assertSame(
            'sandbox:contained: Gray-zone action auto-approved to run inside the configured sandbox.',
            $event->reason,
        );
    }

    public function test_tokimo_and_agentrun_sandboxes_also_contain(): void
    {
        foreach (['tokimo', 'agentrun'] as $provider) {
            $decider = $this->decider(sandbox: new SandboxConfig(provider: $provider, mode: 'full'));
            $batch = $decider->decide($this->interrupt([
                self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
            ]), 'run the migration');

            $this->assertSame('auto', $batch['status'], "provider {$provider} should contain");
            $this->assertSame('sandbox', $batch['events'][0]->source);
        }
    }

    public function test_without_sandbox_gray_action_keeps_the_review_path(): void
    {
        $decider = $this->decider(reviewer: $this->denyingReviewer());
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        // No sandbox: the gray action is reviewed and denied, not contained.
        $this->assertSame('auto', $batch['status']);
        $this->assertSame('reject', $batch['decisions'][0]->type);
        $this->assertSame('review', $batch['events'][0]->source);
        $this->assertSame('reject', $batch['events'][0]->decision);
    }

    public function test_filesystem_mode_sandbox_does_not_contain_bash(): void
    {
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'native', mode: 'filesystem'),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        $this->assertSame('review', $batch['events'][0]->source);
    }

    public function test_local_provider_is_not_containment_even_in_full_mode(): void
    {
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'local', mode: 'full'),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        $this->assertSame('review', $batch['events'][0]->source);
    }

    public function test_red_line_action_is_not_exempted_by_the_sandbox(): void
    {
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'native', mode: 'full'),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('gray-1', 'Bash', ['command' => self::GRAY_COMMAND]),
            self::approvable('red-1', 'Bash', ['command' => self::RED_COMMAND]),
        ]), 'inspect things');

        $this->assertSame('human', $batch['status']);
        $this->assertCount(2, $batch['events']);
        $red = null;
        $collateral = null;
        foreach ($batch['events'] as $event) {
            if ($event->actionId === 'red-1') {
                $red = $event;
            } else {
                $collateral = $event;
            }
        }
        $this->assertNotNull($red);
        $this->assertSame('escalate', $red->decision);
        $this->assertSame('rule', $red->source);
        $this->assertStringStartsWith('rule:red_line:', $red->reason);
        // The sandbox-contained sibling becomes collateral; it must NOT have
        // been silently approved while the batch waits for a human.
        $this->assertNotNull($collateral);
        $this->assertSame('escalate', $collateral->decision);
        $this->assertSame('batch', $collateral->source);
    }

    public function test_non_bash_gray_actions_are_not_contained(): void
    {
        // Write to a path escaping the workspace is ASK, and unknown tools are
        // ASK — neither is sandbox-exempted. A gray non-Bash action is hard to
        // produce by design; chmod-style grays only exist for Bash. Assert the
        // containment predicate stays Bash-scoped via an ASK escalation.
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'native', mode: 'full'),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Write', ['file_path' => '/etc/passwd', 'content' => 'x']),
        ]), 'write config');

        $this->assertSame('human', $batch['status']);
        $this->assertSame('rule', $batch['events'][0]->source);
        $this->assertStringStartsWith('rule:ask:', $batch['events'][0]->reason);
    }

    public function test_auto_mode_is_unaffected_by_the_sandbox(): void
    {
        $decider = new SmartInterruptDecider(
            mode: 'auto',
            reviewer: null,
            cwd: $this->cwd,
            fallbackSessionId: 'session-test',
            sandbox: new SandboxConfig(provider: 'native', mode: 'full'),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::RED_COMMAND]),
        ]), 'do everything');

        $this->assertSame('auto', $batch['status']);
        $this->assertSame('rule', $batch['events'][0]->source);
        $this->assertSame('approve', $batch['events'][0]->decision);
    }

    // ─── feature C: user-saved allow rules ──────────────────────────────

    public function test_allowlisted_command_exempts_a_red_line(): void
    {
        $decider = $this->decider(
            allowlist: HitlAllowlist::fromFile($this->allowlistFile(self::RED_COMMAND)),
            reviewer: $this->denyingReviewer(), // must not be consulted
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::RED_COMMAND]),
        ]), 'inspect things');

        $this->assertSame('auto', $batch['status']);
        $this->assertCount(1, $batch['decisions']);
        $this->assertSame('approve', $batch['decisions'][0]->type);
        $event = $batch['events'][0];
        $this->assertSame('approve', $event->decision);
        $this->assertSame('rule', $event->source);
        $this->assertSame('low', $event->riskLevel);
        $this->assertSame('allowlist:user_rule: User-saved allow rule.', $event->reason);
    }

    public function test_allowlist_match_trims_the_action_command(): void
    {
        $decider = $this->decider(
            allowlist: HitlAllowlist::fromFile($this->allowlistFile(self::RED_COMMAND)),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => '  '.self::RED_COMMAND.'  ']),
        ]), 'inspect things');

        $this->assertSame('auto', $batch['status']);
        $this->assertSame('approve', $batch['events'][0]->decision);
    }

    public function test_near_miss_commands_do_not_hit_the_allowlist(): void
    {
        foreach (['sudo  ls', 'SUDO ls', 'sudo'] as $command) {
            $decider = $this->decider(
                allowlist: HitlAllowlist::fromFile($this->allowlistFile(self::RED_COMMAND)),
            );
            $batch = $decider->decide($this->interrupt([
                self::approvable('call-1', 'Bash', ['command' => $command]),
            ]), 'inspect things');

            $this->assertSame('human', $batch['status'], "'{$command}' must not match the allowlist");
            // Red lines escalate via rule:, unmatched gray commands via review:.
            $this->assertMatchesRegularExpression('/^(rule|review):/', $batch['events'][0]->reason);
        }
    }

    public function test_allowlist_does_not_apply_to_non_bash_tools(): void
    {
        $decider = $this->decider(
            allowlist: HitlAllowlist::fromFile($this->allowlistFile('whatever')),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'MemoryWrite', ['command' => 'whatever']),
        ]), 'remember this');

        $this->assertSame('human', $batch['status']);
        $this->assertStringStartsWith('rule:ask:', $batch['events'][0]->reason);
    }

    public function test_missing_allowlist_file_falls_back_to_the_normal_path(): void
    {
        $decider = $this->decider(
            allowlist: HitlAllowlist::fromFile(sys_get_temp_dir().'/haocode_allowlist_missing_'.bin2hex(random_bytes(6)).'.json'),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        $this->assertSame('auto', $batch['status']);
        $this->assertSame('review', $batch['events'][0]->source);
        $this->assertSame('reject', $batch['events'][0]->decision);
    }

    public function test_corrupt_allowlist_file_fails_closed_to_the_normal_path(): void
    {
        $path = sys_get_temp_dir().'/haocode_allowlist_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, '{corrupt');
        $this->tempFiles[] = $path;

        $decider = $this->decider(
            allowlist: HitlAllowlist::fromFile($path),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'run the migration');

        $this->assertSame('review', $batch['events'][0]->source);
    }

    public function test_null_allowlist_keeps_existing_behaviour(): void
    {
        $decider = $this->decider(allowlist: null, reviewer: $this->denyingReviewer());
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => self::RED_COMMAND]),
        ]), 'inspect things');

        $this->assertSame('human', $batch['status']);
        $this->assertStringStartsWith('rule:red_line:', $batch['events'][0]->reason);
    }

    public function test_allowlist_and_sandbox_compose(): void
    {
        // An allowlisted red-line command settles via the rule source; an
        // unmatched gray command settles via sandbox containment; no review.
        $decider = $this->decider(
            sandbox: new SandboxConfig(provider: 'native', mode: 'full'),
            allowlist: HitlAllowlist::fromFile($this->allowlistFile(self::RED_COMMAND)),
            reviewer: $this->denyingReviewer(),
        );
        $batch = $decider->decide($this->interrupt([
            self::approvable('red-1', 'Bash', ['command' => self::RED_COMMAND]),
            self::approvable('gray-1', 'Bash', ['command' => self::GRAY_COMMAND]),
        ]), 'inspect things');

        $this->assertSame('auto', $batch['status']);
        $this->assertCount(2, $batch['decisions']);
        foreach ($batch['decisions'] as $decision) {
            $this->assertInstanceOf(HumanDecision::class, $decision);
            $this->assertSame('approve', $decision->type);
        }
        $this->assertSame('rule', $batch['events'][0]->source);
        $this->assertSame('allowlist:user_rule: User-saved allow rule.', $batch['events'][0]->reason);
        $this->assertSame('sandbox', $batch['events'][1]->source);
    }
}

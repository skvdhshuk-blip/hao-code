<?php

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Support\Runtime\SdkRuntime;
use PHPUnit\Framework\TestCase;

final class HumanInterruptTest extends TestCase
{
    public function test_interrupt_round_trips_and_stream_message_is_typed(): void
    {
        $interrupt = new HumanInterrupt(
            'int-1',
            'session-1',
            [new HumanActionRequest('call-1', 'Bash', ['command' => 'pwd'], 'Review command')],
            '2026-07-13T10:00:00+08:00',
            'agent-1',
            'team-1',
        );

        $copy = HumanInterrupt::fromArray($interrupt->toArray());
        $this->assertEquals($interrupt, $copy);
        $message = Message::interrupt($copy);
        $this->assertTrue($message->isInterrupt());
        $this->assertFalse($message->isResult());
        $this->assertSame($copy, $message->interrupt);
        $this->assertSame($copy, (new HumanInterruptException($copy))->interrupt);
    }

    public function test_decision_factories_round_trip(): void
    {
        foreach ([
            HumanDecision::approve('a'),
            HumanDecision::edit('b', ['path' => '/tmp/a']),
            HumanDecision::reject('c', 'not now'),
            HumanDecision::respond('d', ['status' => 'answered', 'answers' => ['yes']]),
        ] as $decision) {
            $this->assertEquals($decision, HumanDecision::fromArray($decision->toArray()));
        }
    }

    public function test_hitl_rejects_ephemeral_sessions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HaoCodeConfig(ephemeral: true, interruptOn: ['Bash' => true]);
    }

    public function test_interrupt_configuration_rejects_unknown_decisions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HaoCodeConfig(ephemeral: false, interruptOn: [
            'Write' => ['allowedDecisions' => ['approve', 'replace-tool']],
        ]);
    }

    public function test_resume_interrupt_requires_the_original_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HaoCodeConfig is required to resume an interrupt');

        HaoCode::resumeInterrupt('session-1', 'interrupt-1', []);
    }

    public function test_stream_resume_interrupt_requires_the_original_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HaoCodeConfig is required to resume an interrupt');

        iterator_to_array(HaoCode::streamResumeInterrupt('session-1', 'interrupt-1', []));
    }

    public function test_background_interrupt_resume_restores_the_retained_worktree_cwd(): void
    {
        $root = sys_get_temp_dir().'/haocode-interrupt-worktree-'.bin2hex(random_bytes(4));
        $storage = $root.'/storage';
        $worktree = $root.'/.claude/worktrees/agent-a1b2c3d4';
        mkdir($worktree, 0755, true);
        SdkRuntime::reset();
        SdkRuntime::boot(dirname(__DIR__, 2), $storage);
        SdkRuntime::app(BackgroundAgentManager::class)->create(
            'agent_demo',
            'Modify the repository',
            'general-purpose',
            worktreePath: $worktree,
            worktreeBranch: 'agent-a1b2c3d4',
        );
        $config = new HaoCodeConfig(cwd: $root, ephemeral: false);
        $interrupt = new HumanInterrupt(
            'int-worktree',
            'session-worktree',
            [],
            date('c'),
            'agent_demo',
        );
        $method = new \ReflectionMethod(HaoCode::class, 'restoreSourceAgentWorkingDirectory');
        $method->setAccessible(true);

        try {
            /** @var HaoCodeConfig $restored */
            $restored = $method->invoke(null, $config, $interrupt);

            $this->assertNotSame($config, $restored);
            $this->assertSame($root, $config->cwd);
            $this->assertSame($worktree, $restored->cwd);
        } finally {
            SdkRuntime::reset();
            $this->removeDirectory($root);
        }
    }

    public function test_sync_child_resume_restores_scoped_run_snapshot(): void
    {
        $root = sys_get_temp_dir().'/haocode-sync-interrupt-'.bin2hex(random_bytes(4));
        $worktree = $root.'/.claude/worktrees/agent-b1c2d3e4';
        mkdir($worktree, 0755, true);
        $config = new HaoCodeConfig(
            cwd: $root,
            model: 'parent-model',
            maxTurns: 50,
            permissionMode: 'default',
            allowedTools: ['*'],
            systemPrompt: 'Parent prompt',
            appendSystemPrompt: 'Parent append',
            ephemeral: false,
        );
        $interrupt = new HumanInterrupt(
            'int-sync-worktree',
            'session-sync-worktree',
            [],
            date('c'),
        );
        $snapshot = [
            'cwd' => $worktree,
            'model' => 'child-model',
            'system_prompt' => 'Child prompt',
            'append_system_prompt' => 'Child append',
            'read_only' => true,
            'max_turns_remaining' => 7,
            'allowed_tools' => ['Read', 'Grep'],
        ];
        $method = new \ReflectionMethod(HaoCode::class, 'restoreInterruptRunConfig');
        $method->setAccessible(true);

        try {
            /** @var HaoCodeConfig $restored */
            $restored = $method->invoke(null, $config, $interrupt, $snapshot);

            $this->assertSame($worktree, $restored->cwd);
            $this->assertSame('parent-model', $restored->model);
            $this->assertSame('Parent prompt', $restored->systemPrompt);
            $this->assertSame('Parent append', $restored->appendSystemPrompt);
            $this->assertSame('plan', $restored->permissionMode);
            $this->assertSame(7, $restored->maxTurns);
            $this->assertSame(['Read', 'Grep'], $restored->allowedTools);
        } finally {
            $this->removeDirectory($root);
        }
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}

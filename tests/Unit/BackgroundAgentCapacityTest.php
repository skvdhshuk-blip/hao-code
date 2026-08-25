<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentBusyException;
use HaoCode\Services\Agent\BackgroundAgentLimits;
use HaoCode\Services\Agent\BackgroundAgentManager;
use PHPUnit\Framework\TestCase;

final class BackgroundAgentCapacityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/haocode-background-capacity-'.bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function test_non_positive_limits_fail_at_construction(): void
    {
        foreach (['maxActivePerRun', 'mailboxMaxMessages', 'messageMaxBytes', 'mailboxMaxBytes'] as $field) {
            $arguments = [
                'maxActivePerRun' => 8,
                'mailboxMaxMessages' => 128,
                'messageMaxBytes' => 65_536,
                'mailboxMaxBytes' => 1_048_576,
            ];
            $arguments[$field] = 0;

            try {
                new BackgroundAgentLimits(...$arguments);
                self::fail("{$field}=0 must fail.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function test_active_limit_is_per_owner_and_waiting_agents_still_count(): void
    {
        $manager = $this->manager(new BackgroundAgentLimits(maxActivePerRun: 2));
        $manager->create('owner_a_1', 'one', 'Explore', ownerRunId: 'run-a');
        $manager->create('owner_a_2', 'two', 'Explore', ownerRunId: 'run-a');
        $manager->markWaitingForInput('owner_a_2', new \HaoCode\Sdk\HumanInterrupt(
            'interrupt-a',
            'session-a',
            [],
            date('c'),
        ));

        try {
            $manager->create('owner_a_3', 'three', 'Explore', ownerRunId: 'run-a');
            self::fail('A waiting agent must continue occupying admission capacity.');
        } catch (BackgroundAgentBusyException $exception) {
            self::assertSame('active_agents', $exception->resource);
            self::assertSame(3, $exception->current);
            self::assertSame(2, $exception->limit);
        }

        $manager->create('owner_b_1', 'other owner', 'Explore', ownerRunId: 'run-b');
        self::assertNotNull($manager->get('owner_b_1'));
        $manager->markCompleted('owner_a_1');
        $manager->create('owner_a_3', 'released slot', 'Explore', ownerRunId: 'run-a');
        self::assertNotNull($manager->get('owner_a_3'));
    }

    public function test_states_without_owner_field_share_the_legacy_global_pool(): void
    {
        $manager = $this->manager(new BackgroundAgentLimits(maxActivePerRun: 1));
        $manager->create('legacy_1', 'old state', 'Explore');
        $path = $this->directory.'/legacy_1.state.json';
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unset($state['owner_run_id']);
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));

        $this->expectException(BackgroundAgentBusyException::class);
        $manager->create('legacy_2', 'new legacy state', 'Explore');
    }

    public function test_message_byte_limit_accepts_boundary_and_rejects_one_extra_byte_atomically(): void
    {
        $entry = ['from' => 'run', 'summary' => null, 'message' => 'x', 'created_at' => time()];
        $exactBytes = strlen(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $manager = $this->manager(new BackgroundAgentLimits(messageMaxBytes: $exactBytes));
        $manager->create('agent_exact', 'prompt', 'Explore', ownerRunId: 'run');
        $manager->queueMessage('agent_exact', 'x', from: 'run');
        self::assertSame(1, $manager->get('agent_exact')['pending_messages']);

        try {
            $manager->queueMessage('agent_exact', 'xx', from: 'run');
            self::fail('One byte beyond the envelope limit must fail.');
        } catch (BackgroundAgentBusyException $exception) {
            self::assertSame('message_bytes', $exception->resource);
            self::assertSame($exactBytes + 1, $exception->current);
        }
        self::assertSame(1, $manager->get('agent_exact')['pending_messages']);
        self::assertCount(1, json_decode(
            (string) file_get_contents($this->directory.'/agent_exact.mailbox.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function test_mailbox_count_and_total_bytes_reject_without_partial_state(): void
    {
        $countManager = $this->manager(new BackgroundAgentLimits(mailboxMaxMessages: 1));
        $countManager->create('count_agent', 'prompt', 'Explore', ownerRunId: 'count-run');
        $countManager->queueMessage('count_agent', 'first');
        try {
            $countManager->queueMessage('count_agent', 'second');
            self::fail('Mailbox count overflow must fail.');
        } catch (BackgroundAgentBusyException $exception) {
            self::assertSame('mailbox_messages', $exception->resource);
        }
        self::assertSame(1, $countManager->get('count_agent')['pending_messages']);

        $bytesDirectory = $this->directory.'/bytes';
        mkdir($bytesDirectory, 0700);
        $entry = ['from' => 'controller', 'summary' => null, 'message' => 'x', 'created_at' => time()];
        $exactMailboxBytes = strlen(json_encode([$entry], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $bytesManager = new BackgroundAgentManager(
            $bytesDirectory,
            limits: new BackgroundAgentLimits(mailboxMaxBytes: $exactMailboxBytes),
        );
        $bytesManager->create('bytes_agent', 'prompt', 'Explore', ownerRunId: 'bytes-run');
        $bytesManager->queueMessage('bytes_agent', 'x');
        try {
            $bytesManager->queueMessage('bytes_agent', 'x');
            self::fail('Mailbox byte overflow must fail.');
        } catch (BackgroundAgentBusyException $exception) {
            self::assertSame('mailbox_bytes', $exception->resource);
        }
        self::assertSame(1, $bytesManager->get('bytes_agent')['pending_messages']);
        foreach (glob($bytesDirectory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($bytesDirectory);
    }

    public function test_admission_lock_caps_concurrent_creates_at_eight(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required.');
        }
        $children = [];
        for ($index = 0; $index < 16; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $this->manager(new BackgroundAgentLimits(maxActivePerRun: 8))->create(
                        'concurrent_'.$index,
                        'prompt',
                        'Explore',
                        ownerRunId: 'shared-run',
                    );
                    exit(0);
                } catch (BackgroundAgentBusyException) {
                    exit(2);
                }
            }
            self::assertGreaterThan(0, $pid);
            $children[] = $pid;
        }

        $successes = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $successes++;
            }
        }
        self::assertSame(8, $successes);
        self::assertCount(8, $this->manager(new BackgroundAgentLimits)->list());
    }

    private function manager(BackgroundAgentLimits $limits): BackgroundAgentManager
    {
        return new BackgroundAgentManager($this->directory, limits: $limits);
    }
}

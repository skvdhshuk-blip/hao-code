<?php

namespace Tests\Unit;

trait BackgroundAgentManagerTestClaimCompletionNoticesConcern
{
    public function test_claim_returns_only_terminal_agents_of_the_named_owner(): void
    {
        $this->manager->create('agent_mine', 'p', 'Explore', 'Mine', null, null, null, 'session-a');
        $this->manager->create('agent_theirs', 'p', 'Explore', 'Theirs', null, null, null, 'session-b');
        $this->manager->create('agent_running', 'p', 'Explore', 'Running', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_mine', 'the answer');
        $this->manager->markCompleted('agent_theirs', 'not for me');

        $claimed = $this->manager->claimCompletionNotices('session-a');

        $this->assertCount(1, $claimed);
        $this->assertSame('agent_mine', $claimed[0]['id']);
        $this->assertSame('the answer', $claimed[0]['last_result']);
    }

    public function test_a_completion_is_claimed_only_once(): void
    {
        $this->manager->create('agent_once', 'p', 'Explore', 'Once', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_once', 'done');

        $this->assertCount(1, $this->manager->claimCompletionNotices('session-a'));
        $this->assertSame([], $this->manager->claimCompletionNotices('session-a'));
    }

    public function test_claim_stamps_the_delivery_time_on_the_stored_state(): void
    {
        $this->manager->create('agent_stamp', 'p', 'Explore', 'Stamp', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_stamp', 'done');

        $this->assertNull($this->manager->get('agent_stamp')['completion_notified_at']);
        $this->manager->claimCompletionNotices('session-a');
        $this->assertIsInt($this->manager->get('agent_stamp')['completion_notified_at']);
    }

    public function test_failed_and_dead_agents_are_claimed_too(): void
    {
        $this->manager->create('agent_failed', 'p', 'Explore', 'Failed', null, null, null, 'session-a');
        $this->manager->create('agent_dead', 'p', 'Explore', 'Dead', null, null, null, 'session-a');
        $this->manager->markError('agent_failed', 'it blew up');
        $this->manager->markDead('agent_dead', 'process vanished');

        $claimed = $this->manager->claimCompletionNotices('session-a');
        $ids = array_column($claimed, 'id');
        sort($ids);

        $this->assertSame(['agent_dead', 'agent_failed'], $ids);
    }

    public function test_marking_a_completion_noticed_suppresses_the_claim(): void
    {
        $this->manager->create('agent_polled', 'p', 'Explore', 'Polled', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_polled', 'done');

        // The model already saw this outcome through TaskGet.
        $this->manager->markCompletionNoticed('agent_polled');

        $this->assertSame([], $this->manager->claimCompletionNotices('session-a'));
    }

    public function test_marking_noticed_keeps_the_first_timestamp(): void
    {
        $this->manager->create('agent_stable', 'p', 'Explore', 'Stable', null, null, null, 'session-a');
        $this->manager->markCompleted('agent_stable', 'done');

        $first = $this->manager->markCompletionNoticed('agent_stable')['completion_notified_at'];
        $second = $this->manager->markCompletionNoticed('agent_stable')['completion_notified_at'];

        $this->assertSame($first, $second);
    }
}

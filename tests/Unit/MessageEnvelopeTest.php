<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\MessageEnvelope;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\PromptFragment;
use HaoCode\Services\Api\ProviderPromptAdapter;
use PHPUnit\Framework\TestCase;

class MessageEnvelopeTest extends TestCase
{
    public function test_model_and_persistence_visibility_are_independent(): void
    {
        $history = new MessageHistory;
        $history->addEnvelope(MessageEnvelope::user(
            'model-only',
            persistence: MessageEnvelope::PERSIST_NONE,
            audience: MessageEnvelope::AUDIENCE_MODEL,
        ));
        $history->addEnvelope(MessageEnvelope::user(
            'ui-only',
            persistence: MessageEnvelope::PERSIST_TRANSCRIPT,
            audience: MessageEnvelope::AUDIENCE_UI,
        ));

        $this->assertSame('model-only', $history->getMessagesForApi()[0]['content']);
        $this->assertSame('ui-only', $history->getPersistableMessages()[0]['content']);
    }

    public function test_sensitive_content_is_redacted_only_for_telemetry(): void
    {
        $history = new MessageHistory;
        $history->addEnvelope(MessageEnvelope::user(
            'private-profile',
            sensitivity: MessageEnvelope::SENSITIVITY_SENSITIVE,
        ));

        $this->assertSame('private-profile', $history->getMessages()[0]['content']);
        $this->assertSame('[redacted]', $history->getTelemetryMessages()[0]['content']);
        $this->assertSame('[redacted]', $history->getTelemetryMessagesForApi()[0]['content']);
    }

    public function test_volatile_penultimate_message_does_not_get_a_cache_breakpoint(): void
    {
        $history = new MessageHistory;
        $history->addUserMessage('first');
        $history->addEnvelope(MessageEnvelope::fromMessage(
            ['role' => 'assistant', 'content' => 'volatile'],
            cacheStability: MessageEnvelope::CACHE_VOLATILE,
        ));
        $history->addUserMessage('last');

        $this->assertSame('volatile', $history->getMessagesForApi()[1]['content']);
    }

    public function test_replay_rebuilds_envelopes_without_provider_cache_metadata(): void
    {
        $history = new MessageHistory;
        $history->replaceMessages([
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
            ['role' => 'user', 'content' => 'three'],
        ]);

        $this->assertSame('two', $history->getMessages()[1]['content']);
        $this->assertArrayNotHasKey('cache_control', $history->getMessages()[1]);
        $this->assertArrayHasKey('cache_control', $history->getMessagesForApi()[1]['content'][0]);
    }

    public function test_prompt_adapter_caches_only_run_stable_fragments(): void
    {
        $adapter = new ProviderPromptAdapter;
        $stable = $adapter->adapt([
            new PromptFragment('system', 'stable'),
        ]);
        $volatile = $adapter->adapt([
            new PromptFragment('turn', 'volatile', PromptFragment::STABILITY_TURN),
        ]);

        $this->assertSame(['type' => 'ephemeral'], $stable[0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $volatile[0]);
    }

    public function test_prompt_sensitivity_metadata_never_leaks_to_provider_shape(): void
    {
        $fragment = new PromptFragment(
            'profile',
            'sensitive-value',
            sensitivity: PromptFragment::SENSITIVITY_SENSITIVE,
        );
        $provider = (new ProviderPromptAdapter)->adapt([$fragment]);

        $this->assertSame('[redacted]', $fragment->telemetryContent());
        $this->assertSame('sensitive-value', $provider[0]['text']);
        $this->assertArrayNotHasKey('sensitivity', $provider[0]);
        $this->assertArrayNotHasKey('source', $provider[0]);
    }
}

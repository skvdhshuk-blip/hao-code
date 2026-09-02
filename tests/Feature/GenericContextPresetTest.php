<?php

namespace Tests\Feature;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Agent\GenericContextPreset;
use HaoCode\Services\Agent\PromptFragment;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;
use Tests\TestCase;

final class GenericContextPresetTest extends TestCase
{
    public function test_generic_preset_keeps_generic_context_without_coding_injections(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSystemPrompt')->willReturn(null);
        $settings->method('getAppendSystemPrompt')->willReturn(null);
        $settings->method('getOutputStyle')->willReturn(null);
        $settings->method('getMemorySummaryLevel')->willReturn('l0');
        $settings->method('getPermissionMode')
            ->willReturn(\HaoCode\Services\Permissions\PermissionMode::Default);

        $memory = $this->createMock(MemoryStoreInterface::class);
        $memory->method('all')->willReturn([
            'preference' => 'Known customer preference.',
        ]);

        $skills = $this->createMock(SkillLoader::class);
        $skills->method('getSkillDescriptions')->willReturn(
            '- lookup-orders: Query order status.',
        );

        $builder = new ContextBuilder(
            settings: $settings,
            toolRegistry: new ToolRegistry(),
            memoryStore: $memory,
            skillLoader: $skills,
            contextPreset: new GenericContextPreset(),
        );

        $prompt = $builder->buildSystemPrompt()[0]['text'];

        $this->assertStringContainsString('embedded PHP AI agent', $prompt);
        $this->assertStringContainsString('Known customer preference.', $prompt);
        $this->assertStringContainsString('lookup-orders', $prompt);
        $this->assertStringNotContainsString('# Environment', $prompt);
        $this->assertStringNotContainsString('# Hao Code Conventions', $prompt);
        $this->assertSame('', $builder->buildTurnContext());
    }

    public function test_prompt_fragments_retain_source_stability_and_sensitivity(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getMemorySummaryLevel')->willReturn('l0');
        $settings->method('getPermissionMode')
            ->willReturn(\HaoCode\Services\Permissions\PermissionMode::Default);
        $memory = $this->createMock(MemoryStoreInterface::class);
        $memory->method('all')->willReturn(['profile' => 'remembered profile']);
        $skills = $this->createMock(SkillLoader::class);
        $skills->method('getSkillDescriptions')->willReturn('');
        $builder = new ContextBuilder(
            $settings,
            new ToolRegistry,
            $memory,
            $skills,
            new GenericContextPreset,
        );

        $bySource = [];
        foreach ($builder->buildSystemPromptFragments() as $fragment) {
            $bySource[$fragment->source] = $fragment;
        }

        $this->assertSame(PromptFragment::STABILITY_RUN, $bySource['base_system_prompt']->stability);
        $this->assertSame(PromptFragment::SENSITIVITY_SENSITIVE, $bySource['long_term_memory']->sensitivity);
        $this->assertSame(PromptFragment::STABILITY_TURN, $builder->buildTurnContextFragment()->stability);

        $builder->buildSystemPrompt();
        $telemetryPrompt = $builder->getTelemetrySystemPrompt()[0]['text'];
        $this->assertStringNotContainsString('remembered profile', $telemetryPrompt);
        $this->assertStringContainsString('[redacted]', $telemetryPrompt);
    }
}

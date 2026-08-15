<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Provider-neutral context for non-coding agents that may still use tools,
 * skills, memory, and output styles.
 *
 * @internal
 */
final class GenericContextPreset implements ContextPresetInterface
{
    public function beginSnapshot(): void {}

    public function endSnapshot(): void {}

    public function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
You are Hao Code, an embedded PHP AI agent. Answer the user's request directly and concisely.

Use only the capabilities provided for this run. Do not claim to have read files, run commands, searched external systems, or changed state unless a tool result confirms it.
PROMPT;
    }

    public function environmentContext(): string
    {
        return '';
    }

    public function projectInstructionsContext(): string
    {
        return '';
    }

    public function conventionsContext(): string
    {
        return '';
    }

    public function turnContext(): string
    {
        return '';
    }
}

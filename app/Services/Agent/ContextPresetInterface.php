<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** @internal */
interface ContextPresetInterface
{
    public function beginSnapshot(): void;

    public function endSnapshot(): void;

    public function defaultSystemPrompt(): string;

    public function environmentContext(): string;

    public function projectInstructionsContext(): string;

    public function conventionsContext(): string;

    public function turnContext(): string;
}

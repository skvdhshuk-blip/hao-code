<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Tools\ToolResult;

trait SupportOpsAgentRegisterAbortHandlerConcern
{

    private function registerAbortHandler(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->line("\n[signal] SIGINT received, aborting agent...");
            $this->abortController->abort();
        });
    }

    private function section(string $title): void
    {
        $this->line("\n--- {$title} ---");
    }

    private function line(string $text): void
    {
        $this->write($text."\n");
    }

    private function write(string $text): void
    {
        ($this->writer)($text);
    }
}

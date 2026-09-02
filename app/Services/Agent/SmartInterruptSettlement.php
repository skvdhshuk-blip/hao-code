<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Services\Hitl\HitlAllowlist;
use HaoCode\Services\Hitl\HitlReviewer;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Tools\ToolUseContext;

/**
 * Settles pending interrupt batches without a human, for the smart and auto HITL modes.
 *
 * Extracted from AgentLoop so the loop aggregate stays within its size budget. The
 * decider is cached per run, because its circuit-breaker state must be run-scoped.
 *
 * @internal
 */
final class SmartInterruptSettlement
{
    private ?SmartInterruptDecider $decider = null;

    private bool $deciderResolved = false;

    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly ToolOrchestrator $toolOrchestrator,
        private readonly RunStateLifecycle $runStateLifecycle,
        private readonly ?AgentRunContext $runContext = null,
    ) {}

    /** Drop the cached decider so a new run starts with fresh circuit-breaker state. */
    public function reset(): void
    {
        $this->decider = null;
        $this->deciderResolved = false;
    }

    /**
     * Attempt to settle a pending interrupt batch without a human.
     *
     * Emits one auto-decision event per action through the registered handler. When
     * the whole batch is auto-decided, the decisions are applied through the exact
     * same path a human resume would take (HumanInterruptCoordinator::resolve against
     * the recorded checkpoint), so validation, checkpoint, and tool-execution
     * semantics are preserved.
     *
     * @param  \Closure(\HaoCode\Sdk\Message): void|null  $autoDecisionHandler
     * @return array<int, array<string, mixed>>|null Resolved tool results, or null when
     *                                               the batch must interrupt for a human.
     */
    public function settle(
        HumanInterrupt $interrupt,
        ToolUseContext $context,
        ?callable $onToolStart,
        ?callable $onToolComplete,
        ?string $workingDirectory,
        string $userPrompt,
        ?\Closure $autoDecisionHandler,
    ): ?array {
        $decider = $this->decider($workingDirectory);
        if ($decider === null) {
            return null; // ask mode: zero behaviour change.
        }

        $batch = $decider->decide($interrupt, $userPrompt);
        foreach ($batch['events'] as $event) {
            if ($autoDecisionHandler !== null) {
                ($autoDecisionHandler)($event);
            }
        }
        if ($batch['status'] !== 'auto') {
            return null;
        }

        $resolution = (new HumanInterruptCoordinator($this->sessionManager, $this->toolOrchestrator))->resolve(
            $interrupt->id,
            $batch['decisions'],
            $context,
            $onToolStart,
            $onToolComplete,
            function () use ($interrupt, $batch): void {
                $this->runStateLifecycle->resume($interrupt->id, $batch['decisions']);
            },
        );

        return $resolution['results'];
    }

    /**
     * Build the per-run smart/auto interrupt decider. Returns null in ask mode so the
     * default path stays untouched. The reviewer reuses the run's provider settings,
     * with the model overridden by hitlReviewModel when set.
     */
    private function decider(?string $workingDirectory): ?SmartInterruptDecider
    {
        if ($this->deciderResolved) {
            return $this->decider;
        }
        $this->deciderResolved = true;

        $mode = $this->runContext?->hitlMode ?? 'ask';
        if (! in_array($mode, ['smart', 'auto'], true)) {
            return null;
        }

        $cwd = $workingDirectory
            ?? $this->runContext?->workingDirectory
            ?? (getcwd() ?: '/');
        $sandbox = $this->runContext?->sandbox;
        if ($sandbox !== null && ! is_dir($cwd)) {
            // A sandbox remote cwd (e.g. '/workspace') usually does not exist
            // on the PHP host; classify against the host project directory
            // instead of failing every action closed.
            $cwd = $this->runContext?->projectDirectory ?? $cwd;
        }
        $allowlistPath = $this->runContext?->hitlAllowlistPath;
        $allowlist = is_string($allowlistPath) && trim($allowlistPath) !== ''
            ? HitlAllowlist::fromFile($allowlistPath)
            : null;
        $reviewer = null;
        if ($mode === 'smart') {
            $settings = $this->runContext?->settings;
            $structuredRunner = $this->runContext?->hitlStructuredRunner;
            if ($structuredRunner === null) {
                throw new \LogicException('Smart HITL requires an injected structured runner.');
            }
            $apiKey = $settings?->getApiKey();
            $baseUrl = $settings?->getBaseUrl();
            $providerType = $settings?->getProviderType();
            $reviewer = new HitlReviewer([
                'apiKey' => is_string($apiKey) && trim($apiKey) !== '' ? trim($apiKey) : null,
                'model' => $this->runContext?->hitlReviewModel ?? $settings?->getModel(),
                'baseUrl' => is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : null,
                'providerType' => is_string($providerType) && trim($providerType) !== '' ? trim($providerType) : null,
                'maxBudgetUsd' => $this->runContext?->budgetLedger?->getLimit(),
                'oauthBearer' => null,
            ], $cwd, $structuredRunner, $this->runContext?->usageAccumulator, $this->runContext?->budgetLedger);
        }

        return $this->decider = new SmartInterruptDecider(
            mode: $mode,
            reviewer: $reviewer,
            cwd: $cwd,
            fallbackSessionId: (string) $this->sessionManager->getSessionId(),
            sandbox: $sandbox,
            allowlist: $allowlist,
        );
    }
}

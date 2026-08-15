<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RunOptions;
use HaoCode\Services\Agent\RunLimits;

/**
 * Canonical internal description of one SDK run.
 *
 * Agent and RunOptions remain the public sources of truth. HaoCodeConfig is
 * adapted here so legacy entry points keep their public shape without making
 * the runtime reconcile three independent configuration objects.
 *
 * @internal
 */
final class RunSpec
{
    private function __construct(
        public readonly Agent $agent,
        public readonly RunOptions $options,
        public readonly HaoCodeConfig $config,
        public readonly RunLimits $limits,
    ) {}

    public static function fromAgent(Agent $agent, ?RunOptions $options = null): self
    {
        $options ??= new RunOptions;
        $config = self::buildConfig($agent, $options);

        return new self(
            $agent,
            $options,
            $config,
            new RunLimits(
                maxTurns: $agent->maxTurns,
                maxTokens: $agent->maxTokens,
                maxBudgetUsd: $options->maxBudgetUsd,
                thinkingEnabled: $agent->thinkingEnabled,
                thinkingBudget: $agent->thinkingBudget,
            ),
        );
    }

    public static function fromConfig(HaoCodeConfig $config): self
    {
        return self::fromAgent(
            Agent::fromConfig($config),
            RunOptions::fromConfig($config),
        );
    }

    private static function buildConfig(Agent $agent, RunOptions $options): HaoCodeConfig
    {
        return new HaoCodeConfig(
            apiKey: $agent->apiKey,
            model: $agent->model,
            baseUrl: $agent->baseUrl,
            providerType: $agent->providerType,
            maxTokens: $agent->maxTokens,
            cwd: $options->cwd,
            maxTurns: $agent->maxTurns,
            maxBudgetUsd: $options->maxBudgetUsd,
            permissionMode: $agent->permissionMode,
            allowedTools: $agent->allowedTools,
            disallowedTools: $agent->disallowedTools,
            systemPrompt: $agent->systemPrompt,
            appendSystemPrompt: $agent->appendSystemPrompt,
            thinkingEnabled: $agent->thinkingEnabled,
            thinkingBudget: $agent->thinkingBudget,
            onText: $options->onText,
            onThinking: $options->onThinking,
            onToolStart: $options->onToolStart,
            onToolComplete: $options->onToolComplete,
            onTurnStart: $options->onTurnStart,
            ephemeral: $options->effectiveEphemeral($agent),
            tools: $agent->tools,
            skills: $agent->skills,
            abortController: $options->abortController,
            sessionId: $agent->sessionId,
            continueSession: $agent->continueSession,
            responseSchema: $options->responseSchema,
            credentialPool: $agent->credentialPool,
            sandbox: $agent->sandbox,
            memorySummaryLevel: $agent->memorySummaryLevel,
            memoryStoragePath: $agent->memoryStoragePath,
            skillDirectories: $agent->skillDirectories,
            recursiveSkillDiscovery: $agent->recursiveSkillDiscovery,
            interruptOn: $agent->interruptOn,
            enableAskUser: $agent->enableAskUser,
            memoryStore: $agent->memoryStore,
            hitlMode: $agent->hitlMode,
            hitlReviewModel: $agent->hitlReviewModel,
            hitlAllowlistPath: $agent->hitlAllowlistPath,
            oauthBearer: $agent->oauthBearer,
            images: $options->images,
            headers: $agent->headers,
            structuredMaxRetries: $agent->structuredMaxRetries,
            webfetchAllowPrivateNetworks: $agent->webfetchAllowPrivateNetworks,
            webfetchPrivateAllowList: $agent->webfetchPrivateAllowList,
            webfetchMaxBytes: $agent->webfetchMaxBytes,
            contextPreset: $agent->contextPreset,
        );
    }
}

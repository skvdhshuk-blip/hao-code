<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\RunOptions;

/** @internal */
final class LegacyHaoCodeConfigAdapter
{
    public static function toAgent(HaoCodeConfig $config, string $name = 'default'): Agent
    {
        return new Agent(
            name: $name,
            apiKey: $config->apiKey,
            model: $config->model,
            baseUrl: $config->baseUrl,
            providerType: $config->providerType,
            maxTokens: $config->maxTokens,
            maxTurns: $config->maxTurns,
            permissionMode: $config->permissionMode,
            allowedTools: $config->allowedTools,
            disallowedTools: $config->disallowedTools,
            systemPrompt: $config->systemPrompt,
            appendSystemPrompt: $config->appendSystemPrompt,
            thinkingEnabled: $config->thinkingEnabled,
            thinkingBudget: $config->thinkingBudget,
            tools: $config->tools,
            skills: $config->skills,
            sandbox: $config->sandbox,
            credentialPool: $config->credentialPool,
            oauthBearer: $config->oauthBearer,
            memorySummaryLevel: $config->memorySummaryLevel,
            memoryStoragePath: $config->memoryStoragePath,
            skillDirectories: $config->skillDirectories,
            recursiveSkillDiscovery: $config->recursiveSkillDiscovery,
            interruptOn: $config->interruptOn,
            enableAskUser: $config->enableAskUser,
            memoryStore: $config->memoryStore,
            hitlMode: $config->hitlMode,
            hitlReviewModel: $config->hitlReviewModel,
            hitlAllowlistPath: $config->hitlAllowlistPath,
            goal: $config->goal,
            goalVerificationRounds: $config->goalVerificationRounds,
            goalReminder: $config->goalReminder,
            planExitPolicy: $config->planExitPolicy,
            ephemeral: $config->ephemeral,
            headers: $config->headers,
            webfetchAllowPrivateNetworks: $config->webfetchAllowPrivateNetworks,
            webfetchPrivateAllowList: $config->webfetchPrivateAllowList,
            webfetchMaxBytes: $config->webfetchMaxBytes,
            sessionId: $config->sessionId,
            continueSession: $config->continueSession,
            structuredMaxRetries: $config->structuredMaxRetries,
            contextPreset: $config->contextPreset,
        );
    }

    public static function toRunOptions(HaoCodeConfig $config): RunOptions
    {
        return (new RunOptions(
            onText: $config->onText,
            onThinking: $config->onThinking,
            onToolStart: $config->onToolStart,
            onToolComplete: $config->onToolComplete,
            onTurnStart: $config->onTurnStart,
            images: $config->images,
            ephemeral: $config->ephemeral,
            responseSchema: $config->responseSchema,
            abortController: $config->abortController,
            cwd: $config->cwd,
            maxBudgetUsd: $config->maxBudgetUsd,
        ))->retainLegacyAllowCwdOverride($config->allowCwdOverride);
    }

    public static function toConfig(
        Agent $agent,
        ?RunOptions $options = null,
        bool $allowCwdOverride = false,
    ): HaoCodeConfig
    {
        $options ??= new RunOptions;

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
            goal: $agent->goal,
            goalVerificationRounds: $agent->goalVerificationRounds,
            goalReminder: $agent->goalReminder,
            planExitPolicy: $agent->planExitPolicy,
            oauthBearer: $agent->oauthBearer,
            images: $options->images,
            headers: $agent->headers,
            structuredMaxRetries: $agent->structuredMaxRetries,
            webfetchAllowPrivateNetworks: $agent->webfetchAllowPrivateNetworks,
            webfetchPrivateAllowList: $agent->webfetchPrivateAllowList,
            webfetchMaxBytes: $agent->webfetchMaxBytes,
            allowCwdOverride: $allowCwdOverride,
            contextPreset: $agent->contextPreset,
        );
    }
}

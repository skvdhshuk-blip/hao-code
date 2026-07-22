<?php

namespace HaoCode\Sdk;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/**
 * A reusable agent definition.
 *
 * Agent is the first-class abstraction for an AI agent in HaoCode. It captures
 * everything that stays the same across runs: the model, system prompt, tools,
 * permissions, and sandbox. To execute it once, pass it to {@see Runner::run()}.
 *
 * @example
 *   $agent = new Agent(
 *       name: 'code-reviewer',
 *       model: 'claude-sonnet-4',
 *       tools: [new ReadTool(), new GrepTool()],
 *       maxTurns: 50,
 *   );
 *
 *   $result = Runner::run($agent, 'Review this PR');
 *
 * @api
 */
class Agent
{
    /**
     * @api
     */
    public function __construct(
        /** @api */
        public readonly string $name = 'default',

        /** @api */
        public readonly ?string $model = null,

        /** @api */
        public readonly ?string $apiKey = null,

        /** @api */
        public readonly ?string $baseUrl = null,

        /** @api */
        public readonly ?string $providerType = null,

        /** @api */
        public readonly ?int $maxTokens = null,

        /** @api */
        public readonly int $maxTurns = 50,

        /** @api */
        public readonly ?string $systemPrompt = null,

        /** @api */
        public readonly ?string $appendSystemPrompt = null,

        /** @api */
        public readonly bool $thinkingEnabled = false,

        /** @api */
        public readonly int $thinkingBudget = 10000,

        /** @api */
        public readonly string $permissionMode = 'default',

        /**
         * @api
         * @var string[]
         */
        public readonly array $allowedTools = [],

        /**
         * @api
         * @var string[]
         */
        public readonly array $disallowedTools = [],

        /**
         * @api
         * @var SdkTool[]
         */
        public readonly array $tools = [],

        /**
         * @api
         * @var SdkSkill[]
         */
        public readonly array $skills = [],

        /** @api */
        public readonly ?SandboxConfig $sandbox = null,

        /** @api */
        public readonly ?CredentialPool $credentialPool = null,

        /** @api */
        public readonly ?bool $oauthBearer = null,

        /** @api */
        public readonly string $memorySummaryLevel = 'l0',

        /** @api */
        public readonly ?string $memoryStoragePath = null,

        /**
         * @api
         * @var string[]
         */
        public readonly array $skillDirectories = [],

        /** @api */
        public readonly bool $recursiveSkillDiscovery = false,

        /**
         * @api
         * @var array<string, bool|array<string, mixed>>
         */
        public readonly array $interruptOn = [],

        /** @api */
        public readonly bool $enableAskUser = false,

        /** @api */
        public readonly ?MemoryStoreInterface $memoryStore = null,

        /** @api */
        public readonly ?string $hitlMode = null,

        /** @api */
        public readonly ?string $hitlReviewModel = null,

        /** @api */
        public readonly ?string $hitlAllowlistPath = null,

        /**
         * Disable session and tool-result persistence for this agent's runs.
         * Single-turn runs usually keep this true; durable sessions or HITL
         * flows must set it to false.
         *
         * @api
         */
        public readonly bool $ephemeral = true,

        /**
         * Extra HTTP request headers merged into every provider request
         * (e.g. GitHub Copilot's `Editor-Version` / `Copilot-Integration-Id`).
         * Mirrors {@see HaoCodeConfig::$headers}; invalid entries are filtered
         * out when the run configuration is built.
         *
         * @api
         *
         * @var array<string, string>
         */
        public readonly array $headers = [],

        /**
         * Whether WebFetch may reach private/loopback/reserved IP ranges.
         * Mirrors {@see HaoCodeConfig::$webfetchAllowPrivateNetworks}.
         *
         * @api
         */
        public readonly bool $webfetchAllowPrivateNetworks = false,

        /**
         * CIDR allowlist that bypasses the WebFetch SSRF guard.
         * Mirrors {@see HaoCodeConfig::$webfetchPrivateAllowList}.
         *
         * @api
         *
         * @var list<string>
         */
        public readonly array $webfetchPrivateAllowList = [],

        /**
         * Hard cap on decompressed response bytes per WebFetch request.
         * Mirrors {@see HaoCodeConfig::$webfetchMaxBytes}.
         *
         * @api
         */
        public readonly int $webfetchMaxBytes = 5_242_880,

        /**
         * Session ID to resume. Mirrors {@see HaoCodeConfig::$sessionId}.
         *
         * @api
         */
        public readonly ?string $sessionId = null,

        /**
         * Continue the most recent session in the working directory.
         * Mirrors {@see HaoCodeConfig::$continueSession}.
         *
         * @api
         */
        public readonly bool $continueSession = false,

        /**
         * Number of retry attempts when structured output fails JSON or
         * schema validation. Mirrors {@see HaoCodeConfig::$structuredMaxRetries}.
         *
         * @api
         */
        public readonly int $structuredMaxRetries = 1,
    ) {}

    /**
     * Return a new Agent with the given tools appended.
     *
     * @api
     *
     * @param SdkTool[] $tools
     */
    public function withTools(array $tools): self
    {
        return new self(...array_merge($this->toArray(), ['tools' => array_merge($this->tools, $tools)]));
    }

    /**
     * Return a new Agent with an additional tool.
     *
     * @api
     */
    public function withTool(SdkTool $tool): self
    {
        return $this->withTools([$tool]);
    }

    /**
     * Return a new Agent with the given system prompt.
     *
     * @api
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(...array_merge($this->toArray(), ['systemPrompt' => $systemPrompt]));
    }

    /**
     * Return a new Agent with the given model.
     *
     * @api
     */
    public function withModel(string $model): self
    {
        return new self(...array_merge($this->toArray(), ['model' => $model]));
    }

    /**
     * Return a new Agent with the given maximum turns.
     *
     * @api
     */
    public function withMaxTurns(int $maxTurns): self
    {
        return new self(...array_merge($this->toArray(), ['maxTurns' => $maxTurns]));
    }

    /**
     * Return a new Agent with the given permission mode.
     *
     * @api
     */
    public function withPermissionMode(string $permissionMode): self
    {
        return new self(...array_merge($this->toArray(), ['permissionMode' => $permissionMode]));
    }


    /**
     * Convert this Agent into a HaoCodeConfig.
     *
     * @internal
     */
    public function toConfig(): HaoCodeConfig
    {
        return new HaoCodeConfig(
            apiKey: $this->apiKey,
            model: $this->model,
            baseUrl: $this->baseUrl,
            providerType: $this->providerType,
            maxTokens: $this->maxTokens,
            maxTurns: $this->maxTurns,
            permissionMode: $this->permissionMode,
            allowedTools: $this->allowedTools,
            disallowedTools: $this->disallowedTools,
            systemPrompt: $this->systemPrompt,
            appendSystemPrompt: $this->appendSystemPrompt,
            thinkingEnabled: $this->thinkingEnabled,
            thinkingBudget: $this->thinkingBudget,
            tools: $this->tools,
            skills: $this->skills,
            sandbox: $this->sandbox,
            credentialPool: $this->credentialPool,
            oauthBearer: $this->oauthBearer,
            memorySummaryLevel: $this->memorySummaryLevel,
            memoryStoragePath: $this->memoryStoragePath,
            skillDirectories: $this->skillDirectories,
            recursiveSkillDiscovery: $this->recursiveSkillDiscovery,
            interruptOn: $this->interruptOn,
            enableAskUser: $this->enableAskUser,
            memoryStore: $this->memoryStore,
            hitlMode: $this->hitlMode,
            hitlReviewModel: $this->hitlReviewModel,
            hitlAllowlistPath: $this->hitlAllowlistPath,
            ephemeral: $this->ephemeral,
            headers: $this->headers,
            webfetchAllowPrivateNetworks: $this->webfetchAllowPrivateNetworks,
            webfetchPrivateAllowList: $this->webfetchPrivateAllowList,
            webfetchMaxBytes: $this->webfetchMaxBytes,
            sessionId: $this->sessionId,
            continueSession: $this->continueSession,
            structuredMaxRetries: $this->structuredMaxRetries,
        );
    }

    /**
     * Create an Agent from an existing HaoCodeConfig.
     *
     * @api
     */
    public static function fromConfig(HaoCodeConfig $config, string $name = 'default'): self
    {
        return new self(
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
            ephemeral: $config->ephemeral,
            headers: $config->headers,
            webfetchAllowPrivateNetworks: $config->webfetchAllowPrivateNetworks,
            webfetchPrivateAllowList: $config->webfetchPrivateAllowList,
            webfetchMaxBytes: $config->webfetchMaxBytes,
            sessionId: $config->sessionId,
            continueSession: $config->continueSession,
            structuredMaxRetries: $config->structuredMaxRetries,
        );
    }

    /**
     * Represent this Agent as a tool that can be used by another Agent.
     *
     * The returned tool invokes the agent with a fresh Runner run and returns
     * the resulting text.
     *
     * @api
     */
    public function asTool(string $toolName, string $description): SdkTool
    {
        return new AgentAsTool($toolName, $description, $this);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(): array
    {
        return [
            'name' => $this->name,
            'model' => $this->model,
            'apiKey' => $this->apiKey,
            'baseUrl' => $this->baseUrl,
            'providerType' => $this->providerType,
            'maxTokens' => $this->maxTokens,
            'maxTurns' => $this->maxTurns,
            'systemPrompt' => $this->systemPrompt,
            'appendSystemPrompt' => $this->appendSystemPrompt,
            'thinkingEnabled' => $this->thinkingEnabled,
            'thinkingBudget' => $this->thinkingBudget,
            'permissionMode' => $this->permissionMode,
            'allowedTools' => $this->allowedTools,
            'disallowedTools' => $this->disallowedTools,
            'tools' => $this->tools,
            'skills' => $this->skills,
            'sandbox' => $this->sandbox,
            'credentialPool' => $this->credentialPool,
            'oauthBearer' => $this->oauthBearer,
            'memorySummaryLevel' => $this->memorySummaryLevel,
            'memoryStoragePath' => $this->memoryStoragePath,
            'skillDirectories' => $this->skillDirectories,
            'recursiveSkillDiscovery' => $this->recursiveSkillDiscovery,
            'interruptOn' => $this->interruptOn,
            'enableAskUser' => $this->enableAskUser,
            'memoryStore' => $this->memoryStore,
            'hitlMode' => $this->hitlMode,
            'hitlReviewModel' => $this->hitlReviewModel,
            'hitlAllowlistPath' => $this->hitlAllowlistPath,
            'ephemeral' => $this->ephemeral,
            'headers' => $this->headers,
            'webfetchAllowPrivateNetworks' => $this->webfetchAllowPrivateNetworks,
            'webfetchPrivateAllowList' => $this->webfetchPrivateAllowList,
            'webfetchMaxBytes' => $this->webfetchMaxBytes,
            'sessionId' => $this->sessionId,
            'continueSession' => $this->continueSession,
            'structuredMaxRetries' => $this->structuredMaxRetries,
        ];
    }
}

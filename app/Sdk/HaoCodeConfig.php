<?php

namespace HaoCode\Sdk;

/**
 * Configuration for HaoCode SDK queries.
 *
 * Modeled after Claude Agent SDK's ClaudeAgentOptions — a single config
 * object that controls model, tools, permissions, cost limits, and callbacks.
 *
 * @api
 */
class HaoCodeConfig
{
    public function __construct(
        /**
         * Anthropic API key. Falls back to config('haocode.api_key').
         *
         * @api
         */
        public readonly ?string $apiKey = null,

        /**
         * Model identifier (e.g., 'claude-sonnet-4-20250514').
         *
         * @api
         */
        public readonly ?string $model = null,

        /**
         * API base URL (for custom endpoints / proxies).
         *
         * @api
         */
        public readonly ?string $baseUrl = null,

        /**
         * Provider wire format: 'anthropic' (default), 'openai' (Responses
         * API), or 'openai_chat' (Chat Completions — use for aihubmix,
         * DeepSeek, vLLM, and other OpenAI-compatible gateways). Only honoured
         * when one of {apiKey, baseUrl, model, maxTokens} is also set, since
         * otherwise the SDK falls back to whatever is in settings.json.
         *
         * @api
         */
        public readonly ?string $providerType = null,

        /**
         * Maximum output tokens per response.
         *
         * @api
         */
        public readonly ?int $maxTokens = null,

        /**
         * Working directory for tool execution. Defaults to getcwd().
         *
         * @api
         */
        public readonly ?string $cwd = null,

        /**
         * Maximum agent turns (tool-use round trips). Default: 50.
         *
         * @api
         */
        public readonly int $maxTurns = 50,

        /**
         * Maximum spending in USD before stopping. null = no limit.
         *
         * @api
         */
        public readonly ?float $maxBudgetUsd = null,

        /**
         * Permission mode: 'default', 'plan', 'accept_edits', 'bypass_permissions'.
         * Default: 'bypass_permissions' (SDK consumers handle their own safety).
         *
         * @api
         */
        public readonly string $permissionMode = 'bypass_permissions',

        /**
         * Tools to allow. ['*'] = all (default). Use tool names like ['Bash', 'Read', 'Write'].
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $allowedTools = ['*'],

        /**
         * Tools to deny. Takes precedence over allowedTools.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $disallowedTools = [],

        /**
         * Custom system prompt. null = use default.
         *
         * @api
         */
        public readonly ?string $systemPrompt = null,

        /**
         * Text appended to the default system prompt.
         *
         * @api
         */
        public readonly ?string $appendSystemPrompt = null,

        /**
         * Enable extended thinking.
         *
         * @api
         */
        public readonly bool $thinkingEnabled = false,

        /**
         * Thinking token budget when thinking is enabled.
         *
         * @api
         */
        public readonly int $thinkingBudget = 10000,

        /**
         * Streaming text callback: fn(string $delta): void
         * Called for each text chunk as it arrives from the model.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onText = null,

        /**
         * Tool start callback: fn(string $toolName, array $input): void
         * Called when a tool begins execution.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolStart = null,

        /**
         * Tool complete callback: fn(string $toolName, ToolResult $result): void
         * Called when a tool finishes execution.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolComplete = null,

        /**
         * Turn start callback: fn(int $turnNumber): void
         * Called at the start of each agent turn.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onTurnStart = null,

        /**
         * Reserved for future use. Session persistence cannot currently be disabled.
         *
         * @internal
         */
        public readonly bool $ephemeral = false,

        /**
         * Custom tools to register (instances of SdkTool).
         *
         * @api
         *
         * @var SdkTool[]
         */
        public readonly array $tools = [],

        /**
         * Custom skills to register (instances of SdkSkill).
         * Skills are named prompt templates the agent can invoke.
         *
         * @api
         *
         * @var SdkSkill[]
         */
        public readonly array $skills = [],

        /**
         * AbortController for cancellation from external code.
         *
         * @api
         */
        public readonly ?AbortController $abortController = null,

        /**
         * Session ID to resume a previous conversation.
         *
         * @api
         */
        public readonly ?string $sessionId = null,

        /**
         * Continue the most recent session in the working directory.
         *
         * @api
         */
        public readonly bool $continueSession = false,

        /**
         * JSON schema for structured output (used with HaoCode::structured()).
         *
         * @api
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $responseSchema = null,
    ) {}

    /**
     * Create a minimal config for quick queries.
     *
     * @api
     */
    public static function make(?string $apiKey = null, ?string $model = null): self
    {
        return new self(apiKey: $apiKey, model: $model);
    }

    /**
     * Build a tool filter callable from allowedTools/disallowedTools.
     *
     * @api
     */
    public function toolFilter(): ?callable
    {
        if ($this->allowedTools === ['*'] && $this->disallowedTools === []) {
            return null;
        }

        return function (string $toolName): bool {
            if (in_array($toolName, $this->disallowedTools, true)) {
                return false;
            }

            if (in_array('*', $this->allowedTools, true)) {
                return true;
            }

            return in_array($toolName, $this->allowedTools, true);
        };
    }
}

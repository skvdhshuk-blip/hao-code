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
         * Streaming thinking callback: fn(string $delta): void
         * Called for each reasoning/thinking chunk (Anthropic extended-thinking,
         * DeepSeek-R1 `reasoning_content`, qwen reasoning, etc.). For most
         * reasoning models the final answer arrives via `onText`, but some
         * proxies/models emit the answer inside `reasoning_content` and never
         * produce a visible `content` block — consumers that need to capture
         * everything should hook this in addition to {@see $onText}.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onThinking = null,

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
         * Disable session and tool-result persistence for this run.
         * The basic no-config HaoCode::query() path enables this automatically.
         *
         * @api
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

        /**
         * Optional credential pool for multi-key rotation. When set, the SDK
         * wraps the resolved LlmProvider with a PooledProvider decorator.
         *
         * Single-key users can leave this null — all existing behaviour is
         * unchanged (BC preserved).
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\CredentialPool $credentialPool = null,

        /**
         * Optional sandbox runtime. When set, file/search tools operate inside
         * the sandbox instead of directly against the PHP host cwd.
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\Sandbox\SandboxConfig $sandbox = null,

        /**
         * Memory summary level for system prompt injection.
         *
         * - 'l0': Compact one-liners (~50 tokens each) — default.
         * - 'l1': Structured overviews (~500 tokens each).
         * - 'l2': Full memory content.
         *
         * The agent can always use MemoryRead tool to fetch more detail.
         *
         * @api
         */
        public readonly string $memorySummaryLevel = 'l0',

        /**
         * Custom storage path for SessionMemory. When set, memory is persisted
         * to this file instead of the default ~/.haocode/memory.json.
         *
         * Useful for SDK consumers that want isolated memory stores (e.g.,
         * per-project or per-use-case memory files).
         *
         * @api
         */
        public readonly ?string $memoryStoragePath = null,

        /**
         * Additional directories containing <name>/SKILL.md packages.
         * Directories are loaded only when explicitly configured.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $skillDirectories = [],

        /**
         * Recursively discover nested SKILL.md packages in all skill sources.
         * Disabled by default; shallow packages win same-name collisions.
         *
         * @api
         */
        public readonly bool $recursiveSkillDiscovery = false,
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
        if ($this->allowedTools === ['*'] && $this->disallowedTools === [] && $this->sandbox === null) {
            return null;
        }

        return function (string $toolName): bool {
            if ($this->sandbox !== null) {
                if (in_array($toolName, self::sandboxLocalOnlyToolsToDisable(), true)) {
                    return false;
                }
                if ($toolName === 'Bash' && ! $this->sandbox->enablesBash()) {
                    return false;
                }
            }

            if (in_array($toolName, $this->disallowedTools, true)) {
                return false;
            }

            if (in_array('*', $this->allowedTools, true)) {
                return true;
            }

            return in_array($toolName, $this->allowedTools, true);
        };
    }

    /**
     * @internal
     *
     * @return string[]
     */
    private static function sandboxLocalOnlyToolsToDisable(): array
    {
        return [
            'Edit',
            'apply_patch',
            'NotebookEdit',
            'Lsp',
            'EnterWorktree',
            'ExitWorktree',
            'Agent',
            'SendMessage',
        ];
    }

    /**
     * Working directory exposed to tools.
     *
     * @internal
     */
    public function effectiveWorkingDirectory(): ?string
    {
        return $this->sandbox?->remoteCwd ?? $this->cwd;
    }
}

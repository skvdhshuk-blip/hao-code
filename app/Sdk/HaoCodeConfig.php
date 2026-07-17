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
    /**
     * Human-in-the-loop approval mode:
     *
     * - 'ask' (default): every configured action interrupts for a human decision.
     * - 'smart': rules fast-path routine actions, gray-area actions are reviewed
     *   by a model (see {@see $hitlReviewModel}), and only dangerous actions
     *   interrupt for a human decision.
     * - 'auto': tool interrupts are suppressed entirely; AskUserQuestion still
     *   interrupts for a human response.
     *
     * Unknown values are normalized to 'ask'.
     *
     * @api
     */
    public readonly string $hitlMode;

    /**
     * Model used to review gray-area actions when {@see $hitlMode} is 'smart'.
     * null reuses the current run's model. Non-string and empty values are
     * normalized to null.
     *
     * @api
     */
    public readonly ?string $hitlReviewModel;

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
         * Default: 'default'. Use 'bypass_permissions' only when the caller
         * explicitly accepts responsibility for tool execution.
         *
         * @api
         */
        public readonly string $permissionMode = 'default',

        /**
         * Tools to allow. No tools are exposed by default. Use tool names like
         * ['Bash', 'Read', 'Write'], or ['*'] to explicitly allow all tools.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $allowedTools = [],

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
         * Disable session and tool-result persistence for this run. Enabled by
         * default; durable conversations and human-in-the-loop flows must set
         * this to false explicitly.
         *
         * @api
         */
        public readonly bool $ephemeral = true,

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
         * Add MemoryRead to allowedTools when the agent should fetch more detail.
         *
         * @api
         */
        public readonly string $memorySummaryLevel = 'l0',

        /**
         * Custom storage path for JsonMemoryStore. When set, memory is persisted
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

        /**
         * Tools that pause before execution for a human decision.
         * Values may be true, false, or an array with allowedDecisions and description.
         *
         * @api
         */
        public readonly array $interruptOn = [],

        /**
         * Register AskUserQuestion as an SDK interrupt tool.
         *
         * @api
         */
        public readonly bool $enableAskUser = false,

        /**
         * Custom long-term memory store. Takes precedence over memoryStoragePath.
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\Memory\MemoryStoreInterface $memoryStore = null,

        /**
         * Human-in-the-loop approval mode: 'ask', 'smart', or 'auto'.
         * Unknown values normalize to 'ask'.
         *
         * @api
         */
        string $hitlMode = 'ask',

        /**
         * Model used to review gray-area actions in 'smart' HITL mode.
         * null reuses the current run's model.
         *
         * @api
         */
        ?string $hitlReviewModel = null,
    ) {
        $this->hitlMode = in_array($hitlMode, ['ask', 'smart', 'auto'], true) ? $hitlMode : 'ask';
        $this->hitlReviewModel = is_string($hitlReviewModel) && trim($hitlReviewModel) !== ''
            ? $hitlReviewModel
            : null;

        if (! in_array($this->memorySummaryLevel, ['l0', 'l1', 'l2'], true)) {
            throw new \InvalidArgumentException('memorySummaryLevel must be l0, l1, or l2.');
        }
        if ($this->ephemeral && ($this->interruptOn !== [] || $this->enableAskUser)) {
            throw new \InvalidArgumentException('Human-in-the-loop requires a durable session; ephemeral must be false.');
        }
        $decisionTypes = ['approve', 'edit', 'reject', 'respond'];
        foreach ($this->interruptOn as $toolName => $review) {
            if (! is_string($toolName) || trim($toolName) === '') {
                throw new \InvalidArgumentException('interruptOn keys must be non-empty exact tool names.');
            }
            if (! is_bool($review) && ! is_array($review)) {
                throw new \InvalidArgumentException("interruptOn.{$toolName} must be true, false, or a review configuration array.");
            }
            if (! is_array($review)) {
                continue;
            }
            if (isset($review['description']) && ! is_string($review['description'])) {
                throw new \InvalidArgumentException("interruptOn.{$toolName}.description must be a string.");
            }
            if (isset($review['allowedDecisions'])) {
                if (! is_array($review['allowedDecisions'])
                    || $review['allowedDecisions'] === []
                    || array_diff($review['allowedDecisions'], $decisionTypes) !== []) {
                    throw new \InvalidArgumentException(
                        "interruptOn.{$toolName}.allowedDecisions must contain approve, edit, reject, or respond.",
                    );
                }
            }
        }
    }

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

    /** @internal */
    public function withResponseSchema(array $schema): self
    {
        $values = get_object_vars($this);
        $values['responseSchema'] = $schema;

        return new self(...$values);
    }
}

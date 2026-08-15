<?php

namespace HaoCode\Sdk;

/**
 * Options for a single agent run.
 *
 * These are the things that change per execution: the prompt, callbacks,
 * whether to persist the session, and any session continuity hints.
 *
 * @example
 *   $result = Runner::run(
 *       $agent,
 *       'Update order 123',
 *       RunOptions::make(ephemeral: false, cwd: '/var/www/app'),
 *   );
 *
 * @api
 */
class RunOptions
{
    /**
     * @api
     */
    public function __construct(
        /**
         * Streaming text callback: fn(string $delta): void
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onText = null,

        /**
         * Streaming thinking callback: fn(string $delta): void
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onThinking = null,

        /**
         * Tool start callback: fn(string $toolName, array $input): void
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolStart = null,

        /**
         * Tool complete callback: fn(string $toolName, ToolResult $result): void
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolComplete = null,

        /**
         * Turn start callback: fn(int $turnNumber): void
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onTurnStart = null,

        /**
         * @api
         *
         * @var string[]|array<string, mixed>[]
         */
        public readonly array $images = [],

        /**
         * Disable session and tool-result persistence for this run.
         *
         * null means "inherit from Agent / HaoCodeConfig". Explicit true/false
         * overrides the agent definition for this run only.
         *
         * @api
         */
        public readonly ?bool $ephemeral = null,

        /**
         * JSON schema for structured output.
         *
         * @api
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $responseSchema = null,

        /**
         * AbortController for cancellation from external code.
         *
         * @api
         */
        public readonly ?AbortController $abortController = null,

        /**
         * Working directory for tool execution. Defaults to getcwd().
         *
         * @api
         */
        public readonly ?string $cwd = null,

        /**
         * Maximum spending in USD before stopping. null = no limit.
         *
         * @api
         */
        public readonly ?float $maxBudgetUsd = null,
    ) {}

    /**
     * Create a minimal RunOptions instance.
     *
     * @api
     */

    /**
     * Create RunOptions from a HaoCodeConfig.
     *
     * @api
     */
    public static function fromConfig(HaoCodeConfig $config): self
    {
        return new self(
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
        );
    }

    public static function make(
        ?string $cwd = null,
        ?bool $ephemeral = null,
    ): self {
        return new self(
            cwd: $cwd,
            ephemeral: $ephemeral,
        );
    }

    /**
     * Return a new RunOptions with the given text callback.
     *
     * @api
     */
    public function withTextCallback(callable $callback): self
    {
        return new self(...array_merge($this->toArray(), ['onText' => $callback]));
    }

    /**
     * Return a new RunOptions with durable session enabled.
     *
     * @api
     */
    public function withDurableSession(): self
    {
        return new self(...array_merge($this->toArray(), ['ephemeral' => false]));
    }

    /**
     * Return a new RunOptions with the given working directory.
     *
     * @api
     */
    public function withCwd(string $cwd): self
    {
        return new self(...array_merge($this->toArray(), ['cwd' => $cwd]));
    }

    /**
     * Convert this RunOptions into a HaoCodeConfig for legacy entry points.
     *
     * @internal
     */
    public function toConfig(Agent $agent): HaoCodeConfig
    {
        return \HaoCode\Sdk\Internal\RunSpec::fromAgent($agent, $this)->config;
    }

    /**
     * Resolve effective ephemeral for this run (options override, else agent).
     *
     * @internal
     */
    public function effectiveEphemeral(Agent $agent): bool
    {
        return $this->ephemeral ?? $agent->ephemeral;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(): array
    {
        return [
            'onText' => $this->onText,
            'onThinking' => $this->onThinking,
            'onToolStart' => $this->onToolStart,
            'onToolComplete' => $this->onToolComplete,
            'onTurnStart' => $this->onTurnStart,
            'images' => $this->images,
            'ephemeral' => $this->ephemeral,
            'responseSchema' => $this->responseSchema,
            'abortController' => $this->abortController,
            'cwd' => $this->cwd,
            'maxBudgetUsd' => $this->maxBudgetUsd,
        ];
    }
}

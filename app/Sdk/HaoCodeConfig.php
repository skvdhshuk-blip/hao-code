<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Permissions\PermissionMode;

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
    use HaoCodeConfigConstructConcern;
    use HaoCodeConfigToolFilterConcern;

    /**
     * Human-in-the-loop approval mode:
     *
     * - 'ask': every configured action interrupts for a human decision.
     * - 'smart': rules fast-path routine actions, gray-area actions are reviewed
     *   by a model (see {@see $hitlReviewModel}), and only dangerous actions
     *   interrupt for a human decision.
     * - 'auto': tool interrupts are suppressed entirely; AskUserQuestion still
     *   interrupts for a human response.
     *
     * null (the default) means "not chosen explicitly": the runtime then
     * resolves the mode from the haocode.hitl_mode config file /
     * HAOCODE_HITL_MODE environment variable (whose own default is 'smart').
     * An explicit 'ask' is always honored as 'ask'. Non-null unknown values
     * throw {@see \InvalidArgumentException} (fail closed — never silently
     * fall through to a looser mode).
     *
     * @api
     */
    public readonly ?string $hitlMode;

    /**
     * Model used to review gray-area actions when {@see $hitlMode} is 'smart'.
     * null reuses the current run's model. Non-string and empty values are
     * normalized to null.
     *
     * @api
     */
    public readonly ?string $hitlReviewModel;

    /**
     * Path to a JSON file with user-saved "always allow" Bash rules (the
     * codex always-allow concept, exact-match v1). In 'smart' mode a Bash
     * action whose trimmed command exactly equals a saved rule is approved
     * before the rule classifier runs — including commands the classifier
     * would otherwise red-line (user sovereignty). A missing, corrupt, or
     * wrong-version file loads as an empty allowlist and never throws.
     * null disables the feature; non-string and empty values normalize to
     * null.
     *
     * File format (frozen):
     * {"version":1,"rules":[{"command":"...","addedAt":"<iso8601>","source":"user"}]}
     *
     * @api
     */
    public readonly ?string $hitlAllowlistPath;

    /**
     * Extra HTTP request headers merged into every provider request (e.g.
     * GitHub Copilot's `Editor-Version` / `Copilot-Integration-Id`).
     *
     * Each provider merges this map into its hardcoded request headers; a
     * custom value wins over the hardcoded default for the same header name
     * (matched case-insensitively), except `Authorization` / `x-api-key`,
     * which always stay under the provider's authentication logic. Entries
     * with non-string keys/values, invalid header names, or CR/LF characters
     * are filtered out.
     *
     * @api
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * Provider wire format: 'anthropic', 'openai', or 'openai_chat'.
     * null means "use settings defaults". Normalized at construction.
     *
     * @api
     */
    public readonly ?string $providerType;
}

<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\Api\ProviderPromptAdapter;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;

class ContextBuilder
{
    /** @var list<PromptFragment> */
    private array $lastSystemPromptFragments = [];

    private const MAX_BASE_PROMPT_CHARS = 60_000;

    private const MAX_APPEND_PROMPT_CHARS = 20_000;

    private const MAX_LONG_TERM_MEMORY_CHARS = 3_000;

    private const MAX_OUTPUT_STYLE_CHARS = 20_000;

    private const MAX_SYSTEM_PROMPT_CHARS = 160_000;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ToolRegistry $toolRegistry,
        private readonly MemoryStoreInterface $memoryStore,
        private readonly SkillLoader $skillLoader,
        private readonly ContextPresetInterface $contextPreset,
        private readonly ?OutputStyleLoader $outputStyleLoader = null,
        private readonly bool $textOnly = false,
        private readonly bool $includeMemoryInTextOnly = false,
    ) {}

    public function buildSystemPrompt(): array
    {
        $this->lastSystemPromptFragments = $this->buildSystemPromptFragments();

        return (new ProviderPromptAdapter)->adapt($this->lastSystemPromptFragments);
    }

    /**
     * Provider-shaped prompt with sensitive fragment content removed for spans.
     *
     * @return array<int, array<string, mixed>>
     * @internal
     */
    public function getTelemetrySystemPrompt(): array
    {
        $fragments = array_map(
            static fn (PromptFragment $fragment): PromptFragment => new PromptFragment(
                $fragment->source,
                $fragment->telemetryContent(),
                $fragment->stability,
                $fragment->sensitivity,
            ),
            $this->lastSystemPromptFragments,
        );

        return (new ProviderPromptAdapter)->adapt($fragments);
    }

    /**
     * @return list<PromptFragment>
     * @internal
     */
    public function buildSystemPromptFragments(): array
    {
        if ($this->textOnly) {
            return $this->buildTextOnlySystemPromptFragments();
        }

        $this->contextPreset->beginSnapshot();

        try {
            return $this->buildAgentSystemPromptFragments();
        } finally {
            $this->contextPreset->endSnapshot();
        }
    }

    /** @return list<PromptFragment> */
    private function buildAgentSystemPromptFragments(): array
    {
        $fragments = [];
        $systemPrompt = $this->settings->getSystemPrompt();
        $basePrompt = is_string($systemPrompt) && trim($systemPrompt) !== ''
            ? $systemPrompt
            : $this->contextPreset->defaultSystemPrompt();
        $fragments[] = $this->fragment(
            'base_system_prompt',
            ContextBudget::truncateFragment($basePrompt, self::MAX_BASE_PROMPT_CHARS),
        );

        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if ($appendPrompt) {
            $fragments[] = $this->fragment(
                'append_system_prompt',
                "\n\n".ContextBudget::truncateFragment($appendPrompt, self::MAX_APPEND_PROMPT_CHARS),
            );
        }

        $fragments[] = $this->fragment('environment', $this->contextPreset->environmentContext());
        $fragments[] = $this->fragment(
            'project_instructions',
            $this->contextPreset->projectInstructionsContext(),
            PromptFragment::SENSITIVITY_SENSITIVE,
        );

        $memory = $this->appendLongTermMemory('');
        if ($memory !== '') {
            $fragments[] = $this->fragment(
                'long_term_memory',
                $memory,
                PromptFragment::SENSITIVITY_SENSITIVE,
            );
        }

        // Load available skills + the progressive-disclosure protocol that
        // tells the model how to consume them. The listing alone is not enough:
        // without this protocol the model will either ignore matching skills
        // or bulk-load every reference/script in a skill directory.
        $skillDescs = $this->skillLoader->getSkillDescriptions();
        if ($skillDescs) {
            $fragments[] = $this->fragment(
                'skills',
                "\n\n# Skills\n\n".$skillDescs."\n\n".$this->getSkillsHowToUse(),
            );
        }

        $fragments[] = $this->fragment('conventions', $this->contextPreset->conventionsContext());

        // Inject active output style instructions
        $activeStyle = $this->settings->getOutputStyle();
        if ($activeStyle && $this->outputStyleLoader) {
            $styleContent = $this->outputStyleLoader->getActiveStyleContent($activeStyle);
            if ($styleContent) {
                $fragments[] = $this->fragment(
                    'output_style',
                    "\n\n# Output Style Instructions\n\n"
                        .ContextBudget::truncateFragment($styleContent, self::MAX_OUTPUT_STYLE_CHARS),
                );
            }
        }

        return $this->truncateFragments($fragments, self::MAX_SYSTEM_PROMPT_CHARS);
    }

    /**
     * Build volatile workspace context for the first user turn.
     *
     * Git status and diffs change while an agent works. Keeping them out of the
     * system prompt lets provider-side prefix caches reuse the same session
     * baseline while this snapshot remains part of the append-only history.
     */
    public function buildTurnContext(): string
    {
        return $this->buildTurnContextFragment()->content;
    }

    /** @internal */
    public function buildTurnContextFragment(): PromptFragment
    {
        if ($this->textOnly) {
            return new PromptFragment('turn_context', '', PromptFragment::STABILITY_TURN);
        }

        $this->contextPreset->beginSnapshot();

        try {
            return new PromptFragment(
                'turn_context',
                $this->contextPreset->turnContext(),
                PromptFragment::STABILITY_TURN,
                PromptFragment::SENSITIVITY_SENSITIVE,
            );
        } finally {
            $this->contextPreset->endSnapshot();
        }
    }

    /**
     * 为未开放任何工具的基础调用构造精简系统提示。
     *
     * 该提示保留用户显式传入的 systemPrompt 和 appendSystemPrompt，
     * 但不注入工具、Skill、Git、项目文件或持久记忆等 coding-agent 上下文。
     */
    /** @return list<PromptFragment> */
    private function buildTextOnlySystemPromptFragments(): array
    {
        // 基础调用使用的最小默认提示，明确模型没有工具可调用。
        $prompt = $this->settings->getSystemPrompt();
        if (! is_string($prompt) || trim($prompt) === '') {
            $prompt = <<<'PROMPT'
You are Hao Code, an embedded PHP AI assistant. Answer the user's request directly and concisely.

You have no tools in this request. Do not claim to have read files, run commands, searched the web, or changed external state.
PROMPT;
        }

        $fragments = [$this->fragment(
            'base_system_prompt',
            ContextBudget::truncateFragment($prompt, self::MAX_BASE_PROMPT_CHARS),
        )];
        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if (is_string($appendPrompt) && trim($appendPrompt) !== '') {
            $fragments[] = $this->fragment(
                'append_system_prompt',
                "\n\n".ContextBudget::truncateFragment($appendPrompt, self::MAX_APPEND_PROMPT_CHARS),
            );
        }

        if ($this->includeMemoryInTextOnly) {
            $memory = $this->appendLongTermMemory('');
            if ($memory !== '') {
                $fragments[] = $this->fragment(
                    'long_term_memory',
                    $memory,
                    PromptFragment::SENSITIVITY_SENSITIVE,
                );
            }
        }

        return $this->truncateFragments($fragments, self::MAX_SYSTEM_PROMPT_CHARS);
    }

    private function fragment(
        string $source,
        string $content,
        string $sensitivity = PromptFragment::SENSITIVITY_INTERNAL,
    ): PromptFragment {
        return new PromptFragment($source, $content, PromptFragment::STABILITY_RUN, $sensitivity);
    }

    /**
     * @param list<PromptFragment> $fragments
     * @return list<PromptFragment>
     */
    private function truncateFragments(array $fragments, int $maxChars): array
    {
        $total = array_sum(array_map(
            static fn (PromptFragment $fragment): int => mb_strlen($fragment->content),
            $fragments,
        ));
        if ($total <= $maxChars) {
            return $fragments;
        }

        $combined = implode('', array_map(
            static fn (PromptFragment $fragment): string => $fragment->content,
            $fragments,
        ));

        return [$this->fragment(
            'budgeted_system_prompt',
            ContextBudget::truncateFragment($combined, $maxChars),
            PromptFragment::SENSITIVITY_SENSITIVE,
        )];
    }

    private function appendLongTermMemory(string $prompt): string
    {
        $level = $this->settings->getMemorySummaryLevel();
        if (! in_array($level, ['l0', 'l1', 'l2'], true)) {
            $level = 'l0';
        }
        $entries = $this->memoryStore->all($level);

        if ($entries !== []) {
            $header = 'Reference data learned in previous sessions. It may be stale or incorrect; prefer the current user request and verified evidence.';
            $lines = [$header];
            $length = strlen($header);

            foreach ($entries as $key => $content) {
                $line = "- {$key}: {$content}";
                if ($length + strlen($line) > self::MAX_LONG_TERM_MEMORY_CHARS) {
                    break;
                }
                $lines[] = $line;
                $length += strlen($line);
            }

            $prompt .= "\n\n# Long-Term Memory\n\n".implode("\n", $lines);
        }

        if ($this->toolRegistry->has('MemoryWrite') || $this->toolRegistry->has('MemoryDelete')) {
            $prompt .= <<<'PROMPT'


# Long-Term Memory Update Policy

- Use MemoryWrite only when the user explicitly asks to remember or update durable information.
- Use MemoryDelete only when the user explicitly asks to forget or remove stored information.
- Never store credentials, access tokens, passwords, or other secrets.
PROMPT;
        }

        return $prompt;
    }

    /**
     * Progressive-disclosure protocol for skills. Mirrors the structure of
     * codex's SKILLS_HOW_TO_USE so the model treats SKILL.md as a workflow
     * entry point rather than a self-contained answer — it should load
     * references/scripts/assets only on demand and announce which skill it
     * picked. The wording is deliberately short; long protocol text would
     * itself defeat the budget the listing tries to respect.
     */
    private function getSkillsHowToUse(): string
    {
        return <<<'TEXT'
## How to use skills

- Discovery: the list above is the skills available in this session (name + description). Skill bodies live on disk under `~/.haocode/skills/<name>/SKILL.md` or `<project>/.haocode/skills/<name>/SKILL.md`.
- Trigger: if the user names a skill (`/name` or plain text) OR the task clearly matches a skill's description, invoke it via the Skill tool for that turn. Multiple matches → use them all. Do not carry skills across turns unless re-mentioned.
- Exact names: when the user names a skill, invoke that exact name even if its description was omitted by the listing budget. Do not infer that an unshown name is missing.
- Discovery: for an implicit match that is not obvious from the displayed descriptions, call the Skill tool with action="search" and a short intent query before falling back.
- Missing/blocked: only report a named skill as missing after an exact Skill invocation or search confirms it is unavailable.
- Progressive disclosure:
  1) After deciding to use a skill, invoke it via the Skill tool and read its SKILL.md body completely before taking task actions.
  2) The Skill tool result includes a `<skill_context directory="...">` header. Resolve relative paths (e.g. `scripts/foo.sh`, `references/api.md`) against that exact directory; never guess `~/.haocode` or `.claude`.
  3) If SKILL.md points to `references/`, use its routing instructions to identify the specific files needed for this request — don't bulk-load.
  4) If `scripts/` exist, prefer running or patching them over retyping large code blocks.
  5) If `assets/` or templates exist, reuse them instead of recreating from scratch.
- Coordination: if multiple skills apply, pick the minimal set and state the order. Announce which skill you're using (one short line). If you skip an obvious skill, say why.
- Context hygiene: progressive disclosure applies to selecting relevant files, not partially reading a selected instruction file. Avoid deep reference-chasing — open only files directly linked from SKILL.md unless blocked.
- Safety: if a skill can't be applied cleanly (missing files, unclear instructions), state the issue, pick the next-best approach, and continue.
TEXT;
    }
}

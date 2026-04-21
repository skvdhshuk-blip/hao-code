# Hao Code

A PHP Agent SDK and interactive CLI for Anthropic, OpenAI Responses, and OpenAI Chat Completions APIs.

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)

`SDK` · `Streaming` · `Sub-agents` · `Teams` · `REPL` · `Hooks` · `Skills` · `Session HUD`

---

## Why Another Agent SDK?

There are plenty of AI coding CLIs — but **PHP agent SDKs barely exist**. If you're building in PHP/Laravel and want to embed an AI agent that can actually *do things* (read files, run commands, search code, coordinate sub-agents), your options are thin.

Hao Code gives you a **fully-featured agent as a Composer package** — not a thin HTTP wrapper, but a real multi-turn agent with 30+ built-in tools, streaming, session persistence, custom tools/skills, cost tracking, and abort control.

```bash
composer require sk-wang/hao-code
```

```php
use HaoCode\Sdk\{HaoCode, HaoCodeConfig};

// One-shot
$result = HaoCode::query('Explain this codebase');
echo $result;        // response text
echo $result->cost;  // $0.03

// Streaming
foreach (HaoCode::stream('Build a REST API') as $msg) {
    if ($msg->type === 'text') echo $msg->text;
}

// Multi-turn
$conv = HaoCode::conversation();
$conv->send('Create a User model');
$conv->send('Add email validation');
$conv->close();

// Structured output
$data = HaoCode::structured('Classify this ticket', $jsonSchema);
echo $data->category;  // 'shipping'

// Custom tools — your PHP code, callable by the agent
$result = HaoCode::query('Find order #123', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

**[Full SDK Documentation →](docs/SDK.md)**

<details>
<summary><strong>SDK feature overview</strong></summary>

| Feature | API |
|---------|-----|
| One-shot query | `HaoCode::query()` |
| Streaming | `HaoCode::stream()` |
| Multi-turn conversation | `HaoCode::conversation()` |
| Session resume/continue | `HaoCode::resume()` / `HaoCode::continueLatest()` |
| Structured JSON output | `HaoCode::structured()` |
| Custom tools (PHP code) | `SdkTool` — 4 methods to implement |
| Custom skills (prompt templates) | `SdkSkill` — named prompts with `$ARGUMENTS` |
| Abort control | `AbortController` — cancel from outside |
| Cost tracking | `$result->cost`, `$result->usage`, `maxBudgetUsd` |
| Streaming callbacks | `onText`, `onToolStart`, `onToolComplete`, `onTurnStart` |
| Multi-provider | Anthropic, ZAI, OpenAI Responses, OpenAI Chat Completions (aihubmix / DeepSeek / vLLM / local OSS) |

</details>

<details>
<summary><strong>Custom tool example — 30 lines of PHP</strong></summary>

```php
use HaoCode\Sdk\SdkTool;

class LookupOrderTool extends SdkTool
{
    public function name(): string { return 'LookupOrder'; }
    public function description(): string { return 'Look up an order by ID.'; }

    public function parameters(): array
    {
        return ['order_id' => ['type' => 'string', 'description' => 'Order ID', 'required' => true]];
    }

    public function handle(array $input): string
    {
        return Order::findOrFail($input['order_id'])->toJson();
    }
}

// Agent now has access to your database
$result = HaoCode::query('Check order #12345 status', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

</details>

---

## Also a CLI

Install globally for an interactive coding agent in the terminal:

![Hao Code terminal screenshot](docs/images/hao-code-terminal.png)

```bash
composer global require sk-wang/hao-code
export PATH="$(composer global config bin-dir --absolute):$PATH"
```

Configure API key:

```bash
mkdir -p ~/.haocode
cat > ~/.haocode/settings.json <<'JSON'
{
  "api_key": "your-api-key-here"
}
JSON
```

Launch:

```bash
hao-code                                          # Interactive REPL
hao-code --print="Explain AgentLoop.php"          # Single-shot
hao-code --continue                               # Resume latest session
hao-code --resume=20260404_abcdef12               # Resume specific session
hao-code --resume=ID --fork-session --name="alt"  # Fork into new branch
```

### CLI flags

| Flag | Purpose |
| --- | --- |
| `-p, --print=` | Run once and exit |
| `-c, --continue` | Reopen latest session |
| `-r, --resume=` | Restore session by ID |
| `--fork-session` | Branch into new transcript |
| `--name=` | Set session display name |
| `--model=` | Override model |
| `--system-prompt=` | Replace system prompt |
| `--append-system-prompt=` | Append extra instructions |
| `--permission-mode=` | Override permission mode |

---

## Built-in Tools

The agent ships with 30+ tools available in both SDK and CLI:

| Group | Tools |
| --- | --- |
| **Shell & Files** | Bash, Read, Edit, Write, Glob, Grep |
| **Agents & Planning** | Agent, SendMessage, TodoWrite, EnterPlanMode, ExitPlanMode |
| **Teams** | TeamCreate, TeamList, TeamDelete |
| **Tasks & Automation** | TaskCreate/Get/List/Update/Stop, CronCreate/Delete/List, Sleep |
| **Code Intelligence** | LspTool, NotebookEdit, EnterWorktree, ExitWorktree |
| **Web & Interaction** | WebSearch, WebFetch, AskUserQuestion, ToolSearch, Skill, Config |

---

## Configuration

Settings are read from `~/.haocode/settings.json` (global) and `.haocode/settings.json` (project).

```json
{
  "api_key": "sk-ant-...",
  "model": "claude-sonnet-4-20250514",
  "permission_mode": "default",
  "stream_output": false
}
```

Multi-provider example (Anthropic + Z.ai + OpenAI Responses + Chat Completions gateway):

```json
{
  "active_provider": "aihubmix",
  "provider": {
    "anthropic": {
      "type": "anthropic",
      "api_key": "sk-ant-...",
      "model": "claude-sonnet-4-20250514"
    },
    "zai": {
      "type": "anthropic",
      "api_key": "your-zai-key",
      "api_base_url": "https://api.z.ai/api/anthropic",
      "model": "glm-5.1"
    },
    "openai": {
      "type": "openai",
      "api_key": "sk-...",
      "api_base_url": "https://api.openai.com",
      "model": "gpt-5-codex"
    },
    "aihubmix": {
      "type": "openai_chat",
      "api_key": "sk-...",
      "api_base_url": "https://aihubmix.com",
      "model": "gpt-4o-mini"
    },
    "deepseek": {
      "type": "openai_chat",
      "api_key": "sk-...",
      "api_base_url": "https://api.deepseek.com",
      "model": "deepseek-reasoner"
    }
  }
}
```

Each provider entry declares a wire format via `type`:

- `"type": "anthropic"` (default when omitted) — talks to `/v1/messages` with
  Anthropic SSE events and prompt-caching. Works with the official Anthropic
  API and any Anthropic-compatible gateway (Z.ai, Kimi Coding, aihubmix, etc.).
- `"type": "openai"` — talks to OpenAI's `/v1/responses` API. Required for
  the official OpenAI endpoint and any gateway that has adopted Responses.
  `reasoning.effort` is derived from `/effort`; prompt-caching is skipped (no
  equivalent on the Responses API).
- `"type": "openai_chat"` — talks to `/v1/chat/completions`. Use this for
  OpenAI-compatible gateways that haven't shipped Responses yet (aihubmix,
  DeepSeek, vLLM, local OSS servers, most third-party proxies). Tool-calls,
  `reasoning_content` → thinking deltas, and usage accounting are translated
  internally.

All three types expose the same streaming surface to the rest of the agent
loop, so the SDK, tools, sub-agents, session resume, and CLI commands behave
identically regardless of which wire format is active.

Switch at runtime with `/provider <name>` or pre-select with
`"active_provider": "<name>"`. Use `/provider` with no argument for an
interactive picker; the listing shows `[anthropic]`, `[openai]`, or
`[openai_chat]` next to each entry.

Auto-loaded project files: `HAOCODE.md`, `CLAUDE.md`, `.haocode/rules/*.md`, `.haocode/memory/MEMORY.md`

---

## Observability (Arize Phoenix)

Every turn, LLM call, and tool invocation can be emitted as OpenTelemetry
spans to [Arize Phoenix](https://arize.com/phoenix) (self-hosted or cloud).
Spans use OpenInference semantic conventions (`AGENT`, `LLM`, `TOOL`) so the
Phoenix UI renders them as proper agent traces out of the box.

Enable via `~/.haocode/settings.json`:

```json
{
  "telemetry": {
    "phoenix": {
      "enabled": true,
      "endpoint": "https://phoenix.example.com",
      "api_key": "...",
      "project_name": "hao-code",
      "redact_messages": false
    }
  }
}
```

Or via env vars (take precedence):

```bash
export HAOCODE_PHOENIX_ENABLED=true
export HAOCODE_PHOENIX_ENDPOINT=https://phoenix.example.com
export HAOCODE_PHOENIX_API_KEY=...
export HAOCODE_PHOENIX_PROJECT=hao-code
export HAOCODE_PHOENIX_REDACT=false      # set to true to scrub prompts/outputs
```

Spans emitted per turn:

| Span | Kind | Key attributes |
|---|---|---|
| `agent.run` | `AGENT` | `input.value`, `output.value`, `session.id`, `llm.token_count.*` |
| `llm.chat` | `LLM` | `llm.model_name`, `llm.provider`, `llm.input_messages.*`, `llm.token_count.*`, `llm.stop_reason` |
| `tool.<name>` | `TOOL` | `tool.name`, `tool.call_id`, `input.value`, `output.value`, `tool.is_error` |

Telemetry is disabled by default — no data leaves the process unless
`enabled` is explicitly set. Tracer initialization failures are swallowed:
if Phoenix is unreachable or the config is malformed, the agent keeps
working silently. `BatchSpanProcessor` flushes on process exit, so
one-shot `HaoCode::query()` calls and short CLI sessions still export.

Check status from the REPL:

```
/telemetry
```

---

## Runnable examples

Both examples in [`examples/`](examples/) work against any configured provider
and exercise the real SDK surface end-to-end:

```bash
# One-shot + streaming + structured JSON weather agent, Open-Meteo tools (no key),
# running through an OpenAI-compatible gateway:
WEATHER_PROVIDER_TYPE=openai_chat \
WEATHER_API_KEY=sk-... \
WEATHER_BASE_URL=https://aihubmix.com \
WEATHER_MODEL=gpt-4o-mini \
php examples/weather-agent.php

# The same script works unchanged against Anthropic (omit env vars and set
# ANTHROPIC_API_KEY in settings.json) or OpenAI Responses:
WEATHER_PROVIDER_TYPE=openai \
WEATHER_API_KEY=sk-... \
WEATHER_MODEL=gpt-5 \
php examples/weather-agent.php
```

See also `examples/support-ops-agent.php` for a larger walkthrough covering
`HaoCode::resume()`, `continueLatest()`, custom `SdkSkill`s, and `AbortController`.

---

## Slash Commands (CLI)

<details>
<summary><strong>Session</strong> — /help /exit /clear /history /resume /branch /rewind /snapshot /transcript /search</summary>
</details>

<details>
<summary><strong>Context & Output</strong> — /status /statusline /stats /context /cost /model /provider /fast /theme /output-style</summary>
</details>

<details>
<summary><strong>Workspace</strong> — /files /diff /commit /review /memory /config /permissions /hooks /skills /mcp /init /doctor /version</summary>
</details>

<details>
<summary><strong>Planning</strong> — /plan /tasks /loop</summary>
</details>

---

## Permissions and Hooks

**Permission modes:** `default` (confirm dangerous ops) · `plan` (read-only) · `accept_edits` (auto-accept file edits) · `bypass_permissions`

```json
{
  "permissions": {
    "allow": ["Bash(git:*)", "Read(*:*)"],
    "deny": ["Bash(rm -rf *)"]
  },
  "hooks": {
    "PreToolUse": [{ "command": "echo 'About to run'", "matcher": "Bash" }],
    "PostToolUse": [{ "command": "notify-send 'Done'" }]
  }
}
```

Hook events: `SessionStart` · `Stop` · `PreToolUse` · `PostToolUse` · `PostToolUseFailure` · `PreCompact` · `PostCompact` · `Notification`

---

## Skills

Create custom skills in `.haocode/skills/` or `~/.haocode/skills/`:

```text
.haocode/skills/
├── commit/SKILL.md
├── review/SKILL.md
└── test/SKILL.md
```

Supports `$ARGUMENTS` substitution, session variables, `allowedTools`, model overrides, and inline shell interpolation. Use `/skills` to inspect.

---

## Teams

Create a group of specialized background agents that collaborate on a shared objective:

```
TeamCreate  →  spawn multiple agents with roles (e.g., architect, reviewer, coder)
TeamList    →  inspect team status and member activity
TeamDelete  →  stop all members and clean up
SendMessage →  "team:<name>" broadcasts to all running members
```

Each team member gets injected context about their teammates and the team objective. Members communicate via `SendMessage` using deterministic agent IDs (`{teamName}_{role}`).

```
# Example: AI creates a research team
TeamCreate(name: "research", task: "Write a conflict analysis", members: [
  {role: "historian", prompt: "Research historical context"},
  {role: "analyst",   prompt: "Analyze military posture"},
  {role: "editor",    prompt: "Compile the final report"}
])

# Broadcast to all members
SendMessage(to: "team:research", message: "All sections done, begin compilation")

# Check status
TeamList(name: "research")
```

---

## Testing

```bash
composer test
# or
php vendor/bin/phpunit
```

---

## Requirements

- PHP 8.1+, Composer
- `pcntl` recommended (signal handling, parallel tools)
- `ripgrep` recommended (fast grep)

---

**[MIT License](LICENSE)** · Built with Laravel Zero · Works with Anthropic, OpenAI Responses, and OpenAI Chat Completions APIs

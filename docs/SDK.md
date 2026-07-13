# Hao Code PHP SDK

Use hao-code as a framework-free PHP library to embed an AI coding agent in your application.

```bash
composer require sk-wang/hao-code
```

Set your API key in the process environment:

```bash
export ANTHROPIC_API_KEY=your-api-key
```

Hao Code does not load `.env` files by itself. If your application already
loads `.env`, you may pass the resulting value through `HaoCodeConfig`.

---

## Table of Contents

- [Quick Start](#quick-start)
- [HaoCode API Reference](#haocode-api-reference)
  - [query()](#query)
  - [stream()](#stream)
  - [conversation()](#conversation)
  - [resume() / continueLatest()](#resume--continuelatest)
  - [structured()](#structured)
- [HaoCodeConfig Reference](#haocodeconfig-reference)
- [Sandbox Runtime](#sandbox-runtime)
- [QueryResult](#queryresult)
- [Custom Tools (SdkTool)](#custom-tools-sdktool)
- [Custom Skills (SdkSkill)](#custom-skills-sdkskill)
- [Streaming Messages](#streaming-messages)
- [Agent Teams](#agent-teams)
- [Multi-turn Conversations](#multi-turn-conversations)
- [Session Resume & Continue](#session-resume--continue)
- [Structured Output](#structured-output)
- [Abort Controller](#abort-controller)
- [Cost Tracking](#cost-tracking)
- [Combining Tools + Skills](#combining-tools--skills)
- [Testing](#testing)

---

## Quick Start

```php
<?php

require __DIR__.'/vendor/autoload.php';

use HaoCode\Sdk\HaoCode;

$result = HaoCode::query('Reply with exactly: HaoCode works');

echo $result->text;
```

With no explicit config, `query()` is text-only, does not expose tools to the
model, and does not write a session file. File and shell access or durable
session storage must be enabled explicitly with `HaoCodeConfig`.

Runnable examples:

- `examples/code-review-agent.php` — compact review-focused demo
- `examples/agentrun-ml-clustering-agent.php` — AgentRun sandbox demo where an agent writes data, creates a pure-Python k-means script, and runs it remotely
- `examples/support-ops-agent.php` — end-to-end support-operations agent using query, stream, conversation, resume, continue, structured output, custom tools, skills, callbacks, and abort wiring

---

## HaoCode API Reference

### query()

Execute a one-shot query. Returns a [`QueryResult`](#queryresult) (implements `Stringable`).

```php
HaoCode::query(string $prompt, ?HaoCodeConfig $config = null): QueryResult
```

```php
$result = HaoCode::query('Explain the auth system');

echo $result;            // response text (Stringable)
echo $result->text;      // same as above, explicit
echo $result->cost;      // estimated cost in USD
echo $result->usage;     // ['input_tokens' => ..., 'output_tokens' => ...]
echo $result->sessionId; // session ID for later resume
```

### stream()

Execute a query and yield typed [`Message`](#streaming-messages) objects as they arrive.

With no explicit config, `stream()` matches `query()`: it is text-only,
ephemeral, and does not expose tools. Pass `HaoCodeConfig` to run an agent.

```php
HaoCode::stream(string $prompt, ?HaoCodeConfig $config = null): Generator<Message>
```

```php
foreach (HaoCode::stream('Explain dependency injection briefly') as $msg) {
    match ($msg->type) {
        'text'        => print($msg->text),
        'tool_start'  => print("⚙ {$msg->toolName}\n"),
        'tool_result' => print("  ✓ done\n"),
        'result'      => print("\nCost: \${$msg->cost}\n"),
        'error'       => print("Error: {$msg->error}\n"),
        default       => null,
    };
}
```

### conversation()

Create a multi-turn conversation with persistent context.

```php
HaoCode::conversation(?HaoCodeConfig $config = null): Conversation
```

```php
$conv = HaoCode::conversation();

$r1 = $conv->send('Create a User model');
echo $r1->text;

$r2 = $conv->send('Add email validation');  // remembers User model
echo $r2->text;

$conv->close();
```

### resume() / continueLatest()

Resume a previous session by ID, or continue the most recent one.

```php
HaoCode::resume(string $sessionId, ?HaoCodeConfig $config = null): Conversation
HaoCode::continueLatest(?string $cwd = null, ?HaoCodeConfig $config = null): Conversation
```

```php
// Resume by ID
$conv = HaoCode::resume('20260407_143022_a1b2c3d4');
$conv->send('Where were we?');

// Continue the latest session in current directory
$conv = HaoCode::continueLatest();
$conv->send('Continue the refactoring');
```

Also works inline via config:

```php
// Resume via config
$result = HaoCode::query('Continue', new HaoCodeConfig(sessionId: 'abc123'));

// Auto-continue latest
$result = HaoCode::query('What were we doing?', new HaoCodeConfig(continueSession: true));
```

### structured()

Extract structured (JSON) data from the agent's response.

```php
HaoCode::structured(string $prompt, array $jsonSchema, ?HaoCodeConfig $config = null): StructuredResult
```

```php
$result = HaoCode::structured('Classify this ticket: "My order is late"', [
    'type' => 'object',
    'properties' => [
        'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical']],
        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
        'summary'  => ['type' => 'string'],
    ],
    'required' => ['category', 'priority', 'summary'],
]);

echo $result->category;   // 'shipping'
echo $result['priority'];  // 'high' (ArrayAccess)
$result->toArray();        // ['category' => 'shipping', ...]
$result->toJson();         // '{"category":"shipping",...}'
```

---

## HaoCodeConfig Reference

All parameters are optional. Pass as named arguments:

```php
$config = new HaoCodeConfig(
    apiKey: 'your-key',
    model: 'claude-sonnet-4-20250514',
    // ...
);
```

### API Connection

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `?string` | `null` | Anthropic API key. Falls back to `config('haocode.api_key')` |
| `model` | `?string` | `null` | Model ID. Falls back to config default |
| `baseUrl` | `?string` | `null` | API endpoint URL (for proxies, custom endpoints) |
| `maxTokens` | `?int` | `null` | Maximum output tokens per response |
| `providerType` | `?string` | `null` | `anthropic`, `openai`, or `openai_chat` wire format |

When any of these are set, the SDK creates a standalone HTTP client (bypassing global settings).

Input budgeting uses the active provider's `context_window` setting. It falls
back to `HAOCODE_CONTEXT_WINDOW` (200000 by default) and reserves both the
configured output tokens and a safety margin before sending a request.

### Agent Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `cwd` | `?string` | `null` | Working directory for tool execution. Defaults to `getcwd()` |
| `maxTurns` | `int` | `50` | Maximum agent turns (tool-use round trips) |
| `maxBudgetUsd` | `?float` | `null` | Cost limit in USD. Agent stops when exceeded |
| `ephemeral` | `bool` | `false` | Disable session and tool-result persistence for this run |
| `permissionMode` | `string` | `'bypass_permissions'` | `'default'`, `'plan'`, `'accept_edits'`, `'bypass_permissions'` |
| `sandbox` | `?SandboxConfig` | `null` | Optional temporary filesystem/shell runtime for tools |
| `credentialPool` | `?CredentialPool` | `null` | Rotate provider credentials and retry rate-limited keys |
| `interruptOn` | `array` | `[]` | Exact tool names to pause before execution; values are `true`, `false`, or review configuration arrays |
| `enableAskUser` | `bool` | `false` | Register `AskUserQuestion` as a serializable host interrupt |

### Prompts

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `systemPrompt` | `?string` | `null` | Replace the default system prompt entirely |
| `appendSystemPrompt` | `?string` | `null` | Append text to the default system prompt |
| `responseSchema` | `?array` | `null` | Override the schema used by `structured()` |

### Tools & Skills

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `allowedTools` | `string[]` | `['*']` | Tools to allow. `['*']` = all |
| `disallowedTools` | `string[]` | `[]` | Tools to deny (takes precedence over allowed) |
| `tools` | `SdkTool[]` | `[]` | Custom tools to register |
| `skills` | `SdkSkill[]` | `[]` | Custom skills to register |
| `skillDirectories` | `string[]` | `[]` | Additional explicit directories containing `<name>/SKILL.md` packages |
| `recursiveSkillDiscovery` | `bool` | `false` | Recursively discover nested Skill packages; shallow same-name packages win |

## Sandbox Runtime

Use `SandboxConfig` when the agent needs file or shell tools but must not mutate
the PHP host project directory. Sandbox mode replaces `Read`, `Write`, `Glob`,
and `Grep` with sandbox-scoped tools. Set `mode: 'full'` to also replace `Bash`
with a sandbox-scoped shell.

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;

$result = HaoCode::query('Inspect the project and write a report.md file', new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::local(
        mode: 'filesystem',
        sync: 'upload-cwd',
        remoteCwd: '/workspace',
    ),
    allowedTools: ['Read', 'Write', 'Grep', 'Glob'],
));
```

Sandbox modes:

| Mode | Replaced tools | Notes |
|------|----------------|-------|
| `filesystem` | `Read`, `Write`, `Glob`, `Grep` | Default; `Bash` is disabled |
| `full` | `Read`, `Write`, `Glob`, `Grep`, `Bash` | Shell commands run inside the sandbox backend |

While sandbox mode is active, host-only tools are disabled by default: `Edit`,
`apply_patch`, `NotebookEdit`, `Lsp`, `EnterWorktree`, `ExitWorktree`, `Agent`,
and `SendMessage`.

### Local backend

The local backend creates an isolated temp directory. With `sync: 'upload-cwd'`,
it copies text files from `cwd` into `remoteCwd`, skipping `.git`,
`node_modules`, `vendor`, caches, binaries, and files over 1MB.

| Sync | Behavior |
|------|----------|
| `none` | Start with an empty sandbox at `remoteCwd` |
| `upload-cwd` | Copy a safe text snapshot of `cwd` into `remoteCwd` |

### Alibaba Cloud AgentRun backend

`SandboxConfig::agentRun()` uses Alibaba Cloud AgentRun as a remote temporary
filesystem and script execution environment. It is useful when the PHP server
must not write project files or execute agent-generated shell commands locally.

Verify credentials and template/instance IDs first:

```bash
export AGENTRUN_ACCOUNT_ID=1887527099427005
export AGENTRUN_API_KEY=ak_xxx
export AGENTRUN_TEMPLATE_NAME=sandbox-lagal
export AGENTRUN_REGION=cn-hangzhou
php scripts/agentrun-verify.php
```

`AGENTRUN_TEMPLATE_NAME` asks AgentRun to create a fresh temporary sandbox from a
template. `AGENTRUN_SANDBOX_ID` is only for an already-created live sandbox
instance. Do not put a template ID into `AGENTRUN_SANDBOX_ID`; AgentRun will
return `sandbox not found`.

```php
use HaoCode\Sdk\Sandbox\SandboxConfig;

$config = new HaoCodeConfig(
    sandbox: SandboxConfig::agentRun(
        accountId: getenv('AGENTRUN_ACCOUNT_ID'),
        sandboxId: getenv('AGENTRUN_SANDBOX_ID') ?: null,
        templateName: getenv('AGENTRUN_TEMPLATE_NAME') ?: 'sandbox-lagal',
        apiKey: getenv('AGENTRUN_API_KEY') ?: null,
        region: getenv('AGENTRUN_REGION') ?: 'cn-hangzhou',
        mode: 'full',
        remoteCwd: '/tmp',
    ),
    allowedTools: ['Read', 'Write', 'Bash'],
);
```

For the current AgentRun code-interpreter template, use a writable directory
under `/tmp`, such as `/tmp/workspace`, for generated files. Creating `/workspace`
at the filesystem root can fail with permission denied. The complete demo in
`examples/agentrun-ml-clustering-agent.php` lets an agent generate data, write a
pure-Python k-means script, run it in AgentRun, and read back the JSON summary.

### Callbacks

| Parameter | Type | Description |
|-----------|------|-------------|
| `onText` | `?callable` | `fn(string $delta): void` — streaming text chunk |
| `onToolStart` | `?callable` | `fn(string $toolName, array $input): void` — tool began |
| `onToolComplete` | `?callable` | `fn(string $toolName, ToolResult $result): void` — tool finished |
| `onTurnStart` | `?callable` | `fn(int $turnNumber): void` — new agent turn |

### Advanced

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `abortController` | `?AbortController` | `null` | Cancellation handle |
| `sessionId` | `?string` | `null` | Resume a previous session in `query()`/`stream()` |
| `continueSession` | `bool` | `false` | Auto-continue latest session in `query()`/`stream()` |
| `thinkingEnabled` | `bool` | `false` | Enable extended thinking |
| `thinkingBudget` | `int` | `10000` | Thinking token budget |

### Factory Method

```php
// Minimal config shorthand
$config = HaoCodeConfig::make(apiKey: 'key', model: 'claude-haiku');
```

---

## QueryResult

Returned by `HaoCode::query()` and `Conversation::send()`. Implements `Stringable`.

```php
$result = HaoCode::query('Hello');

// As string (Stringable)
echo $result;                    // prints response text
echo "Answer: " . $result;      // string concatenation works

// Properties
$result->text;                   // string — response text
$result->usage;                  // array — ['input_tokens' => int, 'output_tokens' => int, ...]
$result->cost;                   // float — estimated cost in USD
$result->sessionId;              // ?string — session ID for resume
$result->turnsUsed;              // int — agent turns consumed

// Helpers
$result->inputTokens();          // int
$result->outputTokens();         // int
```

---

## Custom Tools (SdkTool)

Define domain-specific tools the agent can call. Implement 4 methods:

```php
use HaoCode\Sdk\SdkTool;

class LookupOrderTool extends SdkTool
{
    public function name(): string
    {
        return 'LookupOrder';
    }

    public function description(): string
    {
        return 'Look up an order by ID from the database.';
    }

    public function parameters(): array
    {
        return [
            'order_id' => [
                'type' => 'string',
                'description' => 'The order ID to look up',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $order = Order::findOrFail($input['order_id']);
        return $order->toJson();
    }
}
```

Use it:

```php
$result = HaoCode::query('Find order #12345 and check its status', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

### Parameter Format

Each parameter is `name => options`:

```php
public function parameters(): array
{
    return [
        'city' => [
            'type' => 'string',           // string|integer|number|boolean|array|object
            'description' => 'City name', // shown to the model
            'required' => true,           // default: false
            'enum' => ['NYC', 'LA'],      // optional: restrict values
        ],
    ];
}
```

### Error Handling

Exceptions in `handle()` are caught and sent back to the model as error messages:

```php
public function handle(array $input): string
{
    throw new \RuntimeException('Database connection refused');
    // → model receives: "Database connection refused" as tool error
    // → model can retry or explain the failure to the user
}
```

### Stateful Tools

By default, `SdkTool` is read-only (may be fork-executed in parallel). For stateful tools, override `isReadOnly`:

```php
class ShoppingCart extends SdkTool
{
    private array $items = [];

    public function handle(array $input): string
    {
        $this->items[] = $input['item'];
        return 'Cart: ' . implode(', ', $this->items);
    }

    // Required for state to persist across calls!
    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
```

## Custom Skills (SdkSkill)

Skills are named prompt templates the agent can invoke. Unlike tools (which execute PHP code), skills inject instructions that guide the agent's behavior.

```php
use HaoCode\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for OWASP vulnerabilities',
    prompt: 'Review $ARGUMENTS for injection, XSS, auth bypass, and other OWASP Top 10 issues.',
    allowedTools: ['Read', 'Grep'],  // optional: restrict tools during skill
    model: 'opus',                    // optional: model override
    context: 'inline',                // optional: inline or isolated fork
);

$result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
    skills: [$skill],
));
```

### Skill vs Tool

| | SdkSkill | SdkTool |
|---|---|---|
| What it is | Named prompt template | Executable PHP code |
| How agent uses it | Invokes via `SkillTool`, gets expanded prompt | Calls `handle()` directly |
| `$ARGUMENTS` support | Yes | No |
| Appears in system prompt | Yes (Available skills list) | Yes (API tools list) |
| Can restrict tools | Yes (`allowedTools`) | No |
| Isolated execution | Yes (`context: 'fork'`) | No |
| Returns | Expanded prompt text | `handle()` return string |

File-based skills are loaded from `~/.haocode/skills` and
`<project>/.haocode/skills`. Additional catalogs must be opted in explicitly:

```php
$config = new HaoCodeConfig(
    cwd: __DIR__,
    skillDirectories: [getenv('HOME').'/.claude/skills'],
    recursiveSkillDiscovery: true,
);
```

The system prompt keeps an exact-name index for large catalogs while budgeting
descriptions. The `Skill` tool supports paginated `list` and filtered `search`
actions. Once a skill is invoked, its resolved absolute directory is included
in the tool result so relative references are read from the correct package.

`allowedTools` is enforced for the rest of the current user turn. Multiple
inline skills use the intersection of their tool sets. A skill model override
applies for the rest of that turn and is restored afterward. Forked skills
apply their tool and model settings only inside the child agent.
Standalone `!` shell directives in a skill are converted into normal `Bash`
tool requests; they do not bypass tool permissions, hooks, or skill tool scope.

---

## Streaming Messages

`HaoCode::stream()` yields `Message` objects with these types:

| Type | Fields | Description |
|------|--------|-------------|
| `turn` | `$msg->turnNumber` | A new agent turn started |
| `text` | `$msg->text` | Streaming text delta |
| `tool_start` | `$msg->toolName`, `$msg->toolInput` | Tool execution began |
| `tool_result` | `$msg->toolName`, `$msg->toolOutput`, `$msg->toolIsError` | Tool completed |
| `result` | `$msg->text`, `$msg->usage`, `$msg->cost`, `$msg->sessionId` | Final result |
| `error` | `$msg->error` | An error occurred |
| `interrupt` | `$msg->interrupt`, `$msg->sessionId` | Generation paused for human input; no `result` follows in that stream |

```php
foreach (HaoCode::stream('Refactor the auth module', new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Read', 'Edit', 'Grep', 'Glob'],
)) as $msg) {
    if ($msg->type === 'text') {
        echo $msg->text;  // stream to browser, worker output, or logs
    }

    if ($msg->type === 'tool_start') {
        Log::info("Agent using tool: {$msg->toolName}");
    }

    if ($msg->isResult()) {
        DB::table('usage')->insert([
            'tokens' => $msg->usage['input_tokens'] + $msg->usage['output_tokens'],
            'cost'   => $msg->cost,
        ]);
    }

    if ($msg->isError()) {
        Log::error("Agent error: {$msg->error}");
    }
}
```

### Durable human-in-the-loop

HITL never reads stdin and does not call a blocking PHP approval callback. A
non-streaming call throws `HumanInterruptException`; a streaming call yields one
`interrupt` message and ends. The host stores the interrupt ID and resumes it later:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;

$config = new HaoCodeConfig(
    cwd: __DIR__,
    interruptOn: [
        'Bash' => [
            'allowedDecisions' => ['approve', 'edit', 'reject'],
            'description' => 'Review shell command',
        ],
        'Write' => true,
    ],
    enableAskUser: true,
);

try {
    HaoCode::query('Inspect the app and write report.md', $config);
} catch (HumanInterruptException $e) {
    $interrupt = $e->interrupt; // safe to serialize with toArray()

    $result = HaoCode::resumeInterrupt(
        sessionId: $interrupt->sessionId,
        interruptId: $interrupt->id,
        decisions: array_map(
            fn ($action) => HumanDecision::approve($action->id),
            $interrupt->actions,
        ),
        config: $config,
    );
}
```

Available decisions are:

- `HumanDecision::approve($actionId)` executes the hook-normalized checkpoint input.
- `HumanDecision::edit($actionId, $input)` revalidates new arguments without changing the tool name.
- `HumanDecision::reject($actionId, $feedback)` skips execution and returns error feedback to the model.
- `HumanDecision::respond($actionId, $value)` skips execution and returns the host value as a successful tool result.

`AskUserQuestion` actions allow only `respond` and `reject`. A response is
`['status' => 'answered', 'answers' => [...]]` or
`['status' => 'cancelled', 'answers' => []]`. One answer entry is required per
question; optional questions may use `null`. Hard deny rules and hooks remain
authoritative. Sessions are claimed under a file lock; a `resolving` checkpoint
is not automatically retried after a process crash because side effects may have
already occurred.

Foreground child-agent interrupts bubble directly. Background agents and team
members enter `waiting_for_input`; `TeamAwait` and `TeamCollect` surface their
pending interrupt. Resolve the child interrupt using its own `sessionId`, then
collect the team again.

---

## Agent Teams

Enable the built-in team tools when one query needs several focused agents:

```php
$result = HaoCode::query(
    'Create a research team, wait for every member, then summarize the evidence.',
    new HaoCodeConfig(
        cwd: __DIR__,
        allowedTools: [
            'TeamCreate', 'TeamAwait', 'TeamCollect', 'TeamList', 'TeamDelete',
            'Read', 'Glob', 'Grep',
        ],
    ),
);
```

`TeamCreate` starts the members in the configured `cwd` with the parent run's
model and provider settings. Member prompts are optional; descriptive role
names provide compact defaults for larger teams, and `default_agent_type` can
select one agent type for all members without repeating it. `TeamAwait` blocks until every member has returned
a result or failed and emits one structured JSON aggregate. `TeamCollect`
returns the same aggregate immediately, which is useful for progress checks.
Set `read_only: true` in `TeamCreate` when members must be prevented from
mutating files, including through Bash commands; this is enforced by the
permission layer rather than relying on prompts.
Use `SendMessage` only while a member is `running` or `idle`, and call
`TeamDelete` when the team is no longer needed.

---

## Multi-turn Conversations

`Conversation` maintains persistent context across multiple `send()` calls:

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    tools: [new MyDatabaseTool()],
    maxTurns: 20,
));

// Turn 1 — agent creates a file
$r1 = $conv->send('Create a User model with name, email, and password');
echo $r1->text;

// Turn 2 — agent remembers the User model from turn 1
$r2 = $conv->send('Add email validation and password hashing');
echo $r2->text;

// Turn 3 — agent knows about both previous changes
$r3 = $conv->send('Write a PHPUnit test for the User model');
echo $r3->text;

// Metadata
echo $conv->getTurnCount();   // 3
echo $conv->getCost();        // cumulative cost
echo $conv->getSessionId();   // session ID

// Streaming within conversation
foreach ($conv->stream('Add a factory for the User model') as $msg) {
    if ($msg->type === 'text') echo $msg->text;
}

$conv->close();  // no more sends allowed
```

---

## Session Resume & Continue

Sessions are persisted as JSONL files. Resume from any process:

```php
// First process — create something
$result = HaoCode::query('Create a Laravel migration for orders table');
$sessionId = $result->sessionId;  // save this

// Later process — resume where we left off
$conv = HaoCode::resume($sessionId);
$conv->send('Add a foreign key to users table');

// Or just continue the latest session in the current directory
$conv = HaoCode::continueLatest();
$conv->send('What were we working on?');
```

---

## Structured Output

Extract typed data from AI responses:

```php
// Classify a support ticket
$ticket = HaoCode::structured(
    'Classify: "I was charged twice for my subscription"',
    [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical', 'account']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
            'summary'  => ['type' => 'string'],
            'action'   => ['type' => 'string'],
        ],
        'required' => ['category', 'priority', 'summary'],
    ],
);

echo $ticket->category;     // 'billing'
echo $ticket->priority;     // 'high'
echo $ticket['summary'];    // 'Customer reports duplicate charge' (ArrayAccess)
$ticket->toArray();          // full array
$ticket->toJson();           // JSON string

// Access underlying QueryResult for cost/usage
echo $ticket->queryResult->cost;
```

---

## Abort Controller

Cancel long-running operations from external code:

```php
use HaoCode\Sdk\AbortController;

$abort = new AbortController();

// In a queued job:
$result = HaoCode::query('Refactor the entire codebase', new HaoCodeConfig(
    abortController: $abort,
));

// From a signal handler, timeout, or another thread:
$abort->abort();

// Register cleanup callbacks
$abort->onAbort(function () {
    Log::info('Agent operation was cancelled');
});
```

Works with conversations too:

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    abortController: $abort,
));
$conv->send('Long running task...');
// $abort->abort() will stop the agent mid-execution
```

---

## Cost Tracking

### Per-query cost

```php
$result = HaoCode::query('Analyze this codebase');
echo "Cost: \${$result->cost}";
echo "Input tokens: {$result->inputTokens()}";
echo "Output tokens: {$result->outputTokens()}";
```

### Budget limits

```php
$result = HaoCode::query('Do a big refactoring', new HaoCodeConfig(
    maxBudgetUsd: 5.00,  // stop if cost exceeds $5
));
// Agent auto-stops at 80% ($4.00 warning) and 100% ($5.00 hard stop)
```

### Conversation cumulative cost

```php
$conv = HaoCode::conversation();
$conv->send('Step 1');
$conv->send('Step 2');
echo "Total cost: \${$conv->getCost()}";
```

---

## Combining Tools + Skills

Tools and skills can be used together in a single query:

```php
$result = HaoCode::query('Run a full system health check', new HaoCodeConfig(
    // Custom tool — executes PHP code
    tools: [
        new DatabaseHealthTool(),
        new CacheHealthTool(),
    ],
    // Custom skill — injects a prompt template
    skills: [
        new SdkSkill(
            name: 'health-report',
            description: 'Generate a health report',
            prompt: 'Check all systems using the available health tools, then write a report to health-report.md.',
        ),
    ],
));
```

---

## Testing

The SDK is testable with mock HTTP responses. The test infrastructure uses `MockAnthropicSse` to simulate API responses without real API calls:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Api\StreamingClient;
use Tests\Support\MockAnthropicSse;

// In your test:
$this->app->singleton(StreamingClient::class, function ($app) {
    $requests = [];
    return new StreamingClient(
        apiKey: 'test-key',
        model: 'claude-test',
        baseUrl: 'https://mock.test',
        maxTokens: 4096,
        httpClient: MockAnthropicSse::client([
            MockAnthropicSse::textResponse('Mocked response.'),
        ], $requests),
        settingsManager: null,
    );
});

$result = HaoCode::query('Test prompt');
$this->assertStringContainsString('Mocked response', $result->text);
```

See `tests/Feature/SdkE2ETest.php` for 34 comprehensive examples.

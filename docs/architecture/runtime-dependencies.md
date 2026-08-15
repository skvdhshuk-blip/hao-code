# Runtime dependency rules

This document fixes the dependency direction for the P0 provider and capability work. It describes internal boundaries, not new public SDK APIs.

## Layers

```text
Public SDK definitions
    Agent, RunOptions, HaoCodeConfig, SandboxConfig
        |
        v
SDK composition root
    SdkRunFactory, RunCapabilityGuard, RunCapabilityResolver
        |
        +--------------------+
        v                    v
Agent service contracts      Provider capability contracts
    LlmProvider              ProviderCapabilityRegistry
    AgentLoopFactory         ResolvedProviderCapabilities
        |                    |
        v                    v
Provider dispatch and adapters
    ProviderRegistry, StreamingClient
    AnthropicProvider, OpenAiProvider, OpenAiChatProvider
```

## Rules

1. Public SDK definitions remain stable data and facade APIs. Additive options must preserve existing defaults and must not expose provider wire objects.
2. `SdkRunFactory` is the composition root. It may adapt public SDK configuration to internal services.
3. `Sdk\Internal\RunCapabilityGuard` is the run-scoped authority. It combines `HaoCodeConfig`, the current `SettingsManager`, the real assembled tool names, sandbox settings, permission mode, and budget pricing through `RunCapabilityResolver`.
4. `Services\Api\Capability` does not depend on `HaoCode\Sdk`. It resolves provider, model and endpoint facts from `ResolvedProviderConfig`.
5. Agent services consume `LlmProvider`. They do not select Anthropic, OpenAI Responses or OpenAI Chat adapters directly.
6. `StreamingClient` dispatches through `ProviderRegistry`. Adding another wire adapter must not add provider selection branches to `AgentLoop` or `QueryEngine`.
7. Requested capability preflight runs before provider construction, sandbox creation and MCP connection setup. After assembly, the guard binds the exact `ToolRegistry` names. Runtime mutations are atomic, and `QueryEngine` invokes the guard again at the final provider-I/O boundary.
8. `SettingsManager` is the mutable provider-identity source. `StreamingClient`, `PooledProvider`, capability validation, tracing, and cost tracking resolve it at request time rather than caching separate provider identities.
9. Unknown model or custom endpoint capabilities remain `unknown`. Preflight rejects only capabilities marked `unsupported`.
10. Tests own the conformance contract. Every registered provider runs the same normal-case matrix and the same fault categories, including transport interruption.
11. Host and sandbox `Read` implementations share `BoundedTextFileReader` for line counting, window bounds, cancellation, hashing, and output limits. Backends own byte access; tools own path policy, content-type support, receipts, and presentation.
12. `ContextBuilder` owns generic prompt assembly, memory, Skill disclosure, output style, and the final prompt budget. A `ContextPresetInterface` owns scenario-specific prompt fragments. `CodingContextPreset` remains the default; `GenericContextPreset` is selected only by the explicit `contextPreset` run contract. The selected preset is inherited by forks and persisted in run snapshots.
13. `ToolInterface::call()` returns `ToolResult`. Hooks, truncation, persistence, IPC, callbacks, and background-agent decoration transform that immutable result without rebuilding it from strings. `SdkTool::handle(): string` remains the v1 compatibility adapter.
14. `ToolRegistry` is the tool identity authority. Registration validates the stable name and root input schema; duplicate names fail unless the composition root calls explicit `replace()` for a framework-owned backend substitution. Capability preflight consumes the registry's validated manifest, not a second list assembled from config.
15. A child registry is derived only from the parent's already assembled registry. Filtering may remove tools; child configuration cannot add a missing name, swap its implementation, open another MCP set, or create an independent sandbox.

## Filesystem and search capability matrix

The common invariant is deliberately smaller than a universal filesystem API.
`Read` shares bounded text scanning; `Glob` and `Grep` retain backend-specific
search behavior because their schemas, traversal facilities, and result facts
are not equivalent.

| Runtime | Path and byte authority | `Read` invariant | `Glob` / `Grep` behavior that remains backend-owned |
| --- | --- | --- | --- |
| Host | Canonical host paths and host file handles | `BoundedTextFileReader` owns line windows, output bounds, cancellation, and content hash | Host Glob applies project ignore rules, traversal/time limits, and mtime ordering; Host Grep supports context lines, file types, multiline search, and offsets |
| Local sandbox | Sandbox root mapped to local storage | Same bounded scanner; sandbox tool owns remote-path policy and read receipts | Local backend performs bounded traversal and returns sandbox paths; sandbox tool exposes the common reduced search schema and limitation metadata |
| Native sandbox | Isolated runtime with a local filesystem adapter | Same sandbox Read contract | Delegates file search to its filesystem adapter; process isolation does not redefine search semantics |
| Tokimo | Tokimo filesystem protocol | Same sandbox Read contract | Delegates to the Tokimo protocol and preserves runner response/output bounds |
| AgentRun | Remote AgentRun API plus bounded remote commands | Same line/output contract over remote bytes | Remote command capabilities, visit/result caps, and incomplete-result metadata remain explicit backend facts |

Consequently, no `ReadableFilesystem` / `SearchableFilesystem` facade is added
until at least one more invariant is truly shared. Tool names and user-visible
result shapes remain stable without pretending that all backends have the host
search feature set.

## Change gate

Changes to these boundaries must keep all of the following green:

```bash
php scripts/sdk-bc-check.php --verify
./vendor/bin/phpunit tests/Unit/CapabilityArchitectureTest.php
./vendor/bin/phpunit tests/Provider
composer test
```

The architecture test checks typed dependency boundaries. Behavioral integration
tests prove preflight side-effect ordering, actual tool-manifest binding,
runtime rollback, request-boundary validation, and provider dispatch.

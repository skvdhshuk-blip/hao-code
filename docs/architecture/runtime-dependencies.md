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

1. Public SDK definitions remain stable data and facade APIs. P0 does not add constructor parameters or expose provider wire objects.
2. `SdkRunFactory` is the composition root. It may adapt public SDK configuration to internal services.
3. `Sdk\Internal\RunCapabilityGuard` is the run-scoped authority. It combines `HaoCodeConfig`, the current `SettingsManager`, the real assembled tool names, sandbox settings, permission mode, and budget pricing through `RunCapabilityResolver`.
4. `Services\Api\Capability` does not depend on `HaoCode\Sdk`. It resolves provider, model and endpoint facts from `ResolvedProviderConfig`.
5. Agent services consume `LlmProvider`. They do not select Anthropic, OpenAI Responses or OpenAI Chat adapters directly.
6. `StreamingClient` dispatches through `ProviderRegistry`. Adding another wire adapter must not add provider selection branches to `AgentLoop` or `QueryEngine`.
7. Requested capability preflight runs before provider construction, sandbox creation and MCP connection setup. After assembly, the guard binds the exact `ToolRegistry` names. Runtime mutations are atomic, and `QueryEngine` invokes the guard again at the final provider-I/O boundary.
8. `SettingsManager` is the mutable provider-identity source. `StreamingClient`, `PooledProvider`, capability validation, tracing, and cost tracking resolve it at request time rather than caching separate provider identities.
9. Unknown model or custom endpoint capabilities remain `unknown`. Preflight rejects only capabilities marked `unsupported`.
10. Tests own the conformance contract. Every registered provider runs the same normal-case matrix and the same fault categories, including transport interruption.

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

# Run state contracts

This document fixes the internal P1/P2 state boundaries. It does not add a new
public SDK API and it does not claim exactly-once execution.

## Ownership

| Capability | Authoritative writer | Persistence | Delivery / adapter | Consumers | Compatibility path | Invariant |
| --- | --- | --- | --- | --- | --- | --- |
| Transcript | `SessionManager` | Existing session JSONL | `MessageHistory` / session loader | Conversation resume, summaries, UI | Existing record types remain readable | Transcript records model-visible conversation; it is not an execution claim ledger |
| Run events | `RunJournal` | `RunEventStoreInterface` | JSONL or SQLite store | Export, replay, telemetry/eval adapters | JSONL events coexist with legacy session records | Sequence is monotonic per run; a dedupe key identifies one logical fact |
| Checkpoints | `RunCheckpointStoreInterface` | Incremental state delta plus event cursor | JSONL or SQLite store | HITL and worker recovery | Existing embedded HITL checkpoint remains readable | A checkpoint never copies the complete transcript |
| Tool execution | `ToolExecutionStoreInterface` | SQLite claim ledger | `DurableToolExecutionCoordinator` | Recovery worker | Disabled when no durable store is configured | A non-read-only execution that may have escaped becomes `unknown` and is never auto-retried |
| Memory | `MemoryStoreInterface` | Existing memory backend | Memory tools / prompt builder | Agent runs | Unchanged | Checkpoints and events do not become long-term memory |
| Artifact | Tool backend / `ToolResultStorage` | Existing backend-specific storage | Stable reference, hash and size metadata | Transcript, replay, SDK callers | Unchanged | Events and checkpoints reference artifacts; they do not duplicate large payloads |

## Call chain

```text
Agent / Runner / Conversation / HaoCode
  -> SdkRunFactory
  -> AgentLoopFactory
  -> AgentLoop
     -> RunJournal
     -> QueryEngine -> LlmProvider
     -> ToolOrchestrator -> DurableToolExecutionCoordinator -> ToolInterface
     -> HumanInterruptCoordinator
  -> SessionManager / RunEventStore / RunCheckpointStore
```

## Event contract

`RunEvent` schema version 1 contains a globally unique event id, stable run and
invocation ids, a per-run sequence, optional causation event id, phase, type,
dedupe key, timestamp and provider-neutral payload. Stores reject reuse of a
dedupe key with different content.

The run id is the durable session id. A normal conversation send creates a new
invocation id; resuming an interrupt restores the checkpointed invocation id.
Nested agents own their own run id. `causation_id` is deliberately run-local;
existing agent/session metadata remains the cross-run parent-link authority.

## Durable tool state machine

```text
missing -> claimed -> started -> completed | failed | interrupted | cancelled
                         \----> unknown
```

- Schema/semantic validation may fail before a HITL preparation claim. Direct
  execution conservatively claims before entering the lifecycle pipeline.
- The claim and pre-effect checkpoint are committed before hooks or tool code.
- Every transition requires the current fencing token.
- Lease expiry opens a takeover window; it does not cancel a still-current
  owner. A result may commit after expiry only while owner and fencing token
  remain unchanged.
- Any expired `claimed` call may be reclaimed because tool code has not started;
  an expired `started` call may be reclaimed only when it is read-only.
- A `started` non-read-only call whose result is not committed becomes
  `unknown`; it is never reclaimed automatically.
- Result, terminal state, event and post-result checkpoint are committed in one
  SQLite transaction.

## Recovery boundary

A host-owned recovery worker follows one protocol:

1. `claimRun()` acquires the run lease; an active lease owned by another worker
   stops recovery before provider or tool code is called.
2. `recoverExpiredToolExecutions()` classifies expired claims. A started
   mutation becomes `unknown`; an expired read-only call remains reclaimable.
3. Reclaimed calls receive a strictly higher fencing token. Every start and
   terminal commit must match the current owner and token.
4. The worker renews its run lease while processing and releases it when the
   recovery pass is complete. A stale worker cannot commit through an older
   token.

P2 provides the store and recovery protocol, not a public daemon. SQLite is an
explicit durable-store mode and requires PDO SQLite. JSONL remains the default
session/event adapter so existing installations and public constructors do not
gain a new hard dependency. Durable Worker productization remains a later
roadmap item.

SQLite mode executes tool batches sequentially. Default JSONL runs retain the
existing fork-based parallel read path; a PDO connection is never shared with a
forked tool worker.

# WebSearch multi-engine fan-out contract

Status: implemented contract for the first fan-out release. This document
defines the internal boundary; it does not make the engine layer a public
SDK extension point.

The baseline for this contract is the v1 `WebSearch` text shape plus the
DuckDuckGo/Bing behavior delivered by HAOC-6. The design evidence is the local
`tokimo-package-web-search` implementation and its 2026-09-03 live results. The
Rust code is a design reference, not a source-compatible implementation.

## Decisions

- Fan out concurrently to `bing`, `duckduckgo`, `sogou`, `360`, and `yahoo` by
  default. The default set is a positive allowlist; Google, Baidu, Bilibili,
  and any future experimental engine are not enabled by omission.
- Keep the engine interface and registry under `HaoCode\Tools\WebSearch` and
  mark them `@internal`. Hosts cannot register custom engines in this release.
- Add an optional `engines` tool argument for selecting a non-empty subset of
  the five registered engines. Omission selects the default allowlist.
- Preserve the existing result and no-result text shown to the model. Engine
  provenance, positions, scores, and stats are emitted through
  `ToolResult::$data`, not appended to model-visible text.
- Keep the existing five status values and report one status per selected
  engine. A partial engine failure is data, not a tool failure, when at least
  one usable result remains.
- Deduplicate and score with the SearXNG-inspired tuple and formula, but select
  title and snippet by explicit engine quality priority. Never select a lower
  quality value merely because it is longer.
- Use one Symfony HttpClient and its multiplexed `stream()` boundary. No async
  runtime, paid API, API key, or headless browser is introduced.

## Ownership and invariants

| Capability | Authority | Consumer | Invariant |
| --- | --- | --- | --- |
| Registered and default engine IDs | Internal `EngineRegistry` | Input validation and coordinator | Defaults are a positive allowlist; registration order and response completion order never decide ranking |
| Engine URL and HTML parsing | One `EngineInterface` implementation per engine | Fan-out coordinator | An engine describes a request and parses a bounded response; it does not own transport, TLS, redirects, deadlines, or memory limits |
| HTTP fan-out and warmup | Fan-out coordinator | Engine parsers | One failing or slow response is cancelled independently and cannot discard completed peers |
| Domain policy | Internal `WebSearchDomainPolicy` | Aggregator | Exact hostname or true subdomain matching is unchanged; `blocked_domains` wins over `allowed_domains` |
| Deduplication, merge, and rank | Result aggregator | Text formatter and structured result composer | Equal input facts produce equal output regardless of network completion order |
| Model-visible text | `WebSearchTool` formatter | Provider-neutral agent loop | Result list and no-result wording stay in the v1 Markdown shape; stats never enter model context |
| Host-visible search data | `ToolResult::$data` | `onToolComplete` and internal IPC | Data uses the versioned schema below and survives the existing immutable `ToolResult` pipeline |

No Provider adapter changes are part of this work. `AgentLoop` continues to see
only the normal provider-neutral tool result text.

## Internal engine contract

The implementation should use these responsibilities and signatures. Names of
internal DTO classes may change during implementation, but their fields and
ownership must not be collapsed back into engine-specific transport code.

```php
namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
interface EngineInterface
{
    /** Canonical lowercase ID matching ^[a-z0-9]+(?:-[a-z0-9]+)*$. */
    public function id(): string;

    /** Finite ranking multiplier greater than zero. */
    public function weight(): float;

    /** Higher values win field-level merge conflicts; independent of weight. */
    public function qualityPriority(): int;

    /** Per-search transport deadline measured from this engine's dispatch. */
    public function timeoutMs(): int;

    /** HTTPS homepage used for a best-effort cookie warmup, or null. */
    public function warmupUrl(): ?string;

    /** Build a GET request without performing I/O. */
    public function createRequest(string $query): EngineRequest;

    /** Parse a successful, bounded response without performing further I/O. */
    public function parse(EngineHttpResponse $response): EngineParseResult;
}
```

`EngineRequest` contains only `url` and engine-specific request `headers`.
Engines cannot pass arbitrary HttpClient options: the coordinator owns GET,
TLS verification, redirect count, content decoding, timeouts, and byte limits.

`EngineHttpResponse` contains `statusCode`, `effectiveUrl`, normalized response
`headers`, and the bounded decoded `body`. `parse()` is called only for a 2xx
response that passed the transport and size gates. The effective URL is present
so parsers can identify anti-bot redirects such as Sogou's `antispider` page.

`EngineParseResult` contains one of `success_with_results`, `success_empty`, or
`parse_error`, a list of `RawSearchResult`, and a nullable stable error code.
`RawSearchResult` contains `title`, `url`, `snippet`, `template`, and `imgSrc`.
The first release always emits `template = "default"` and `imgSrc = null`, but
both fields remain in the internal identity so adding another result category
does not silently change deduplication later.

The parser must return at most 10 valid results in original 1-based engine
order. A result needs a non-empty normalized title and an absolute HTTP(S) URL;
invalid entries are skipped. Zero valid entries are `success_empty` only when
the page contains explicit engine-specific no-result evidence. A blank page,
challenge page, or unknown layout is `parse_error`.

### Registry

```php
/** @internal */
final class EngineRegistry
{
    /** @return list<string> */
    public function availableEngineIds(): array;

    /** @return list<string> */
    public function defaultEngineIds(): array;

    public function register(EngineInterface $engine): void;

    /** @return list<EngineInterface> */
    public function resolve(?array $engineIds): array;
}
```

`SdkRuntime` is the only production composition root allowed to register
engines. Registration rejects an invalid ID, duplicate ID, non-finite or
non-positive weight, timeout outside `1..10000`
milliseconds, and a non-HTTPS warmup URL. `resolve(null)` returns the default
allowlist. An empty list, duplicate ID, unknown ID, or non-string value fails
semantic validation before any network request.

Initial catalog:

| ID | Default | Weight | Quality priority | Timeout | Warmup URL |
| --- | --- | ---: | ---: | ---: | --- |
| `bing` | yes | 1.0 | 400 | 5000 ms | `https://www.bing.com/` |
| `duckduckgo` | yes | 1.0 | 500 | 5000 ms | `https://duckduckgo.com/` |
| `sogou` | yes | 1.0 | 300 | 5000 ms | `https://www.sogou.com/` |
| `360` | yes | 1.0 | 200 | 5000 ms | `https://www.so.com/` |
| `yahoo` | yes | 1.0 | 100 | 5000 ms | `https://search.yahoo.com/` |

The exact default order is `bing`, `duckduckgo`, `sogou`, `360`, `yahoo` and is
used only for deterministic request/stat presentation. All initial ranking
weights remain `1.0` because current evidence proves availability, not a
calibrated relevance advantage. Quality priority is deliberately separate:
it selects the cleanest representation without secretly changing rank.

Google is not registered in the first release, so it is neither default nor
selectable. A later implementation may register it as non-default only after a
live non-headless path reliably passes; merely having a parser class is not
sufficient. Known-bad engines are expressed by absence from the positive
default allowlist, not by a second negative list that can drift.

### Why this is not public SDK API

Publishing engine registration would also publish transport privileges,
request lifecycle, parser failure semantics, SSRF responsibility, cookie
isolation, and long-lived worker behavior. Those contracts have not been
validated by external hosts. The roadmap requires a new abstraction to remain
internal until at least three real applications prove the public need.

Hosts that need a private or paid search backend can already supply a separate
`SdkTool`; it does not need to enter the built-in `WebSearch` registry. Public
custom-engine registration requires a separate design and minor release after
that evidence exists.

## Tool input contract

`allowed_domains` and `blocked_domains` retain their current normalization and
true-domain-boundary behavior. In particular, `example.com` matches itself and
`docs.example.com`, but not `notexample.com` or `example.com.evil.com`; blocked
matches win when both lists contain the same domain.

The only addition is `engines`:

```json
{
  "type": "object",
  "properties": {
    "query": {
      "type": "string",
      "minLength": 2
    },
    "engines": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["bing", "duckduckgo", "sogou", "360", "yahoo"]
      },
      "minItems": 1,
      "maxItems": 5,
      "uniqueItems": true
    },
    "allowed_domains": {
      "type": "array",
      "items": {"type": "string"}
    },
    "blocked_domains": {
      "type": "array",
      "items": {"type": "string"}
    }
  },
  "required": ["query"]
}
```

IDs are case-sensitive canonical values; aliases such as `so360` are not part
of the tool contract. Omitting `engines` selects `defaultEngineIds()`. An
explicit empty array is invalid rather than an ambiguous spelling of either
"none" or "all".

## Execution pipeline

```text
validate input and resolve engines
  -> best-effort concurrent warmup for engines not attempted in this process
  -> queue every search request before reading any search response
  -> multiplex and bound response streams independently
  -> classify each engine and parse successful bounded bodies
  -> apply allowed/blocked domain policy to raw results
  -> deduplicate, quality-merge, score, deterministically sort
  -> truncate to 8 results
  -> compose unchanged model text plus versioned ToolResult data
```

Domain filtering happens before aggregation. A filtered result therefore does
not contribute an engine hit, position, or score. `EngineStat.count` still
reports valid parsed results before caller-supplied domain filtering, so it
continues to describe engine health rather than policy acceptance.

## Deduplication, merge, and rank

### Deduplication key

For every valid result, parse the URL and build:

```text
template|host|path|query|fragment|img_src
```

- Lowercase the host, remove one leading `www.` case-insensitively, remove a
  trailing DNS dot, and retain an explicit non-default port.
- Exclude the URL scheme so HTTP and HTTPS variants can merge. Use `/` when
  the path is empty.
- Preserve path case, percent encoding, query pair order, query values, and
  fragment. Do not remove tracking parameters or sort the query: doing so can
  conflate distinct resources without engine-specific evidence.
- Use `default` for `template` and an empty string for absent `img_src` in the
  initial general-web implementation.

Within one engine, repeated occurrences of the same key count as one hit and
keep only the earliest position. Across engines, maintain a map
`engine_id -> earliest 1-based position`; never append a second position for
the same engine.

### Quality merge

Normalize HTML entities and whitespace before comparing candidates. Merge each
field independently:

| Field | Rule |
| --- | --- |
| `title` | Highest `qualityPriority()` among non-empty titles |
| `snippet` | Highest `qualityPriority()` among non-empty snippets; an empty higher-priority snippet does not erase a lower-priority non-empty one |
| `url` | HTTPS variant first, then highest quality priority, then bytewise lexical order |
| `engines` | Unique hit engines ordered by descending quality priority, then ID |
| `positions` | One earliest position per engine |

Equal-priority candidates are resolved by canonical engine ID, not arrival
order. Do not concatenate snippets, infer dates, or choose title/snippet by
length. This directly prevents Yahoo breadcrumb text such as
`PHP https://www.php.net › releases › 8 ...` and stray date text from
replacing the cleaner DuckDuckGo representation of the same URL.

### Score and stable ordering

For the unique hit engines `E` and position map `P`, compute using unrounded
double precision:

```text
combined_weight = product(weight(engine) for engine in E)
hit_count       = count(E)
score           = sum((combined_weight * hit_count) / P[engine] for engine in E)
```

Weights must be finite and positive; positions must be positive integers. For
two weight-1 engines at positions 1 and 3, the score is `2/1 + 2/3 = 2.666...`;
a single weight-1 hit at position 1 scores `1.0`.

Sort by these keys in order:

1. score descending;
2. hit-engine count descending;
3. best position ascending;
4. highest hit-engine quality priority descending;
5. deduplication key in bytewise ascending order.

Sorting uses the full score. Structured output rounds the displayed score to
six decimal places. After sorting, return at most eight results, preserving the
existing maximum model-visible list size.

## Output contract

### Model-visible text

Successful result text remains:

```text
Search results for: "<query>"

1. [<title>](<url>)
   <snippet when non-empty>

2. ...
```

Do not append engine IDs, score, positions, timing, warnings, or a JSON block.
An all-successful search with no post-filter results keeps the exact existing
text:

```text
No search results found for: <query>
```

When no usable result exists and at least one engine failed, keep the existing
error prefix and generalize its deterministic status list:

```text
Web search failed with no usable results. Backend statuses: Bing=http_error, DuckDuckGo=success_empty, Sogou=parse_error, 360=transport_error, Yahoo=http_error.
```

Only selected engines appear, in selection/default order. With usable results,
partial engine failures are intentionally silent in model text: telling the
model about failed redundant backends adds tokens without changing the answer.

### Structured `ToolResult::$data`

Every non-aborted `WebSearch` result, including empty and error results, carries
this host-visible data. It is data, not `ToolResult::$metadata`: metadata is
reserved for runtime coordination such as limits and execution bookkeeping.

```json
{
  "type": "web_search",
  "schema_version": 1,
  "query": "php 8.5 release notes 2026-09-03",
  "selected_engines": ["bing", "duckduckgo", "sogou", "360", "yahoo"],
  "partial": true,
  "results": [
    {
      "rank": 1,
      "title": "PHP: PHP 8.5 Release Announcement",
      "url": "https://www.php.net/releases/8.5/en.php",
      "snippet": "...",
      "engines": ["duckduckgo", "bing"],
      "positions": {"duckduckgo": 1, "bing": 3},
      "score": 2.666667
    }
  ],
  "stats": [
    {
      "engine": "bing",
      "status": "success_with_results",
      "count": 10,
      "elapsed_ms": 807,
      "http_status": 200,
      "error": null
    },
    {
      "engine": "sogou",
      "status": "transport_error",
      "count": 0,
      "elapsed_ms": 5000,
      "http_status": null,
      "error": "timeout"
    }
  ]
}
```

Contract details:

- `results` is exactly the ranked, filtered, maximum-eight list rendered to
  text; the UI never receives a different hidden result set.
- `rank` is 1-based. `positions` is an object keyed by engine ID so provenance
  cannot drift through parallel arrays.
- `stats` contains exactly one row per selected engine in selection/default
  order. `count` is the number of valid parsed raw results before domain
  filtering and deduplication, capped at 10.
- `partial` is true when any stat has `transport_error`, `http_error`, or
  `parse_error`, even if the tool itself succeeds with usable results.
- `elapsed_ms` uses a monotonic clock from search dispatch until completion or
  cancellation and excludes warmup time.
- `http_status` is the final status when headers were received, otherwise null.
- `error` is null on successful statuses and otherwise one stable safe code,
  never a raw exception, response body, query URL, cookie, or challenge body.
- Consumers must select on `schema_version`, ignore unknown additive fields,
  and not parse the Markdown to build search result cards.

Stable initial error codes are `timeout`, `overall_timeout`,
`response_too_large`, `network_error`, `invalid_content_encoding`,
`http_status`, `challenge_page`, and `unexpected_markup`.

## Failure semantics

The existing five statuses remain the only top-level per-engine statuses:

| Status | Meaning |
| --- | --- |
| `success_with_results` | A 2xx bounded response produced at least one valid raw result |
| `success_empty` | A 2xx bounded response contained explicit no-result evidence |
| `transport_error` | DNS, connection, TLS, stream, timeout, total deadline, content decoding, or response-size failure |
| `http_error` | Final HTTP status is outside 200-299 after allowed redirects |
| `parse_error` | Blank/challenge/unknown markup or a page with candidates but no valid parse result |

The coordinator always settles every selected engine into one stat; it never
throws an engine exception out of `WebSearchTool::call()`.

| Final facts | Tool outcome | Model text |
| --- | --- | --- |
| At least one usable post-filter merged result | success | Normal result list, even when `partial = true` |
| No usable result and every selected status is successful | success | Existing no-results line |
| No usable result and any selected status is an error | error | Existing failure prefix plus all selected statuses |
| `ToolUseContext::isAborted()` becomes true | aborted | Existing aborted result semantics |

`success_with_results` may still yield the final no-results success when all
raw results are removed by domain policy and every engine completed
successfully. Conversely, one `success_empty` plus one failed engine is not
enough evidence to claim the search is empty, so no-result-with-failure remains
a tool error.

## Concurrency, warmup, and resource limits

- Queue every selected search with `HttpClientInterface::request()` before
  consuming any of them through one `stream()` loop. Completion order must not
  affect stats order, merge winners, score ties, or final text.
- Use `timeout` and `max_duration` from the engine's remaining 5-second budget,
  and also enforce a monotonic per-engine deadline in the coordinator. A
  timeout cancels only that response and records `transport_error/timeout`.
- The complete `WebSearchTool::call()` has a 10-second monotonic deadline,
  including first-use warmup. At the deadline, cancel only still-pending
  responses, retain already completed engine results, and record
  `transport_error/overall_timeout` for each pending engine.
- Warmup requests run concurrently and are best effort with a 3-second phase
  cap. Attempt each `(HttpClient instance, engine ID)` at most once per PHP
  process; an injected replacement client starts a fresh lifecycle. Capture
  only first-party cookies and send them only to the same HTTPS origin. Warmup
  failure never suppresses the corresponding search request and its body is
  never retained.
- Keep TLS verification enabled and `max_redirects = 3`. An engine cannot
  override either value.
- The 2,097,152-byte limit applies independently to each search response after
  content decoding. Crossing it cancels only that response and records
  `transport_error/response_too_large`; five selected engines therefore have a
  maximum retained decoded-body budget of 10 MiB, plus parser/result overhead.
- Count decoded chunks directly when the transport performs safe streaming
  decompression. A transport that cannot enforce an incremental decoded limit
  must request `identity`; it must not buffer and fully decompress an
  attacker-controlled gzip body before checking the limit.
- Poll `ToolUseContext::isAborted()` inside warmup and search stream loops. On
  abort, cancel every outstanding response immediately and return the existing
  `ToolResult::aborted()` outcome rather than misclassifying cancellation as an
  engine transport failure.

Timeout and cancellation are different facts: reaching a deadline records an
engine stat and can still produce a partial successful search; host abort ends
the tool outcome and does not claim a completed search.

## Compatibility, release, and rollback

This is a backward-compatible feature release, not a patch release:

| Surface | Compatibility result |
| --- | --- |
| PHP `@api` signatures | Unchanged; engine classes remain outside `app/Sdk` and internal |
| SDK public API snapshot | Must remain byte-for-byte unchanged; run `php scripts/sdk-bc-check.php --verify`, do not write a new snapshot |
| Tool input | Additive optional `engines`; existing calls omit it and remain valid |
| Model result text | List/no-result shape and eight-result maximum preserved; result membership/order may change as web search is inherently live |
| Host callback data | Additive `ToolResult::$data` schema v1; consumers gain structured cards without parsing text |
| Failure text | Existing prefix/status vocabulary retained; the status list expands to selected engines |

The target release is the next minor after v1.23.0 (normally v1.24.0). A patch
version would understate the new input and structured data contracts. No major
release is required because no existing PHP signature or documented text shape
is removed, and the SDK has never promised stable live-search membership or
ordering.

Do not add a public `fanOut` flag or emit dual text formats. A transition flag
would create a second long-lived behavior contract while providing no parsing
compatibility benefit: existing model text already stays stable. During the
implementation release, update `README.md` and `docs/SDK.md` to document the
optional `engines` argument and `onToolComplete` data schema.

Operational rollback after release may shrink `defaultEngineIds()` to the
known-good subset or restore serial coordination internally, but it must keep
accepting the released engine IDs and keep emitting data schema v1. Removing an
engine ID or schema v1 after release is a separate compatibility decision.
Before release, the entire implementation can be reverted without migration;
there is no persisted state or public configuration to clean up.

## Implementation verification matrix

| Risk | Required evidence |
| --- | --- |
| Registry boundary | Duplicate/invalid IDs, weights, priorities, timeouts, unknown selections, empty selections, and a default list that excludes Google |
| Per-engine parsing | Recorded fixtures for normal results, explicit empty, blank/challenge page, unknown markup, redirect decoding, and invalid result URLs |
| Fan-out isolation | Responses complete out of order; two of five engines fail while remaining results still succeed; every selected engine produces one stat |
| Final failure truth | All-success empty/filter-empty is success; empty plus any failure is error; partial failure plus a usable result is success |
| Deadlines and bounds | Independent engine timeout, 10-second overall timeout, per-response decoded 2 MiB cap, cancellation of pending peers only, and host abort |
| Dedup and score | `www` normalization, HTTP/HTTPS merge, query/fragment distinctions, same-engine duplicates, cross-engine score boost, and deterministic ties |
| Quality merge | The Yahoo breadcrumb/date regression keeps the cleaner DuckDuckGo title/snippet; lower-priority non-empty snippet fills only an empty higher-priority field |
| Domain policy | Exact/subdomain allow and block boundaries remain unchanged and filtered hits do not contribute to score |
| Output separation | Golden assertions for unchanged Markdown and exact schema-v1 data; scores/stats never appear in `ToolResult::$output` |
| Real engines | Opt-in live smoke for all five defaults, plus a run with two forced failures; record counts and elapsed time separately from fixtures |
| SDK compatibility | `php scripts/sdk-bc-check.php --verify`, focused WebSearch tests, full non-live suite, lint, Composer validation, and audit before release |

Fixture tests prove parser and coordinator semantics. Only the opt-in live smoke
proves that current external HTML endpoints still satisfy those parsers; neither
kind of test substitutes for the other.

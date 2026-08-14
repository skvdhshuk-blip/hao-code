<?php

declare(strict_types=1);

namespace Tests\Feature;

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Sdk\StructuredResultValidationException;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

/**
 * E2E tests for the HaoCode PHP SDK.
 *
 * Tests HaoCode::query(), HaoCode::stream(), and HaoCode::conversation()
 * using mock API responses. Verifies the SDK facade correctly wires
 * into AgentLoop and returns typed Message objects.
 */
class SdkE2ETest extends TestCase
{
    use SdkE2ETestSetUpConcern;
    use SdkE2ETestTestAbortBetweenConversationCreationAndSendDoesNotSendARequestConcern;
    use SdkE2ETestTestMaxBudgetUsdWiresToCostTrackerConcern;
    use SdkE2ETestTestConversationExposesCostAndSessionIdConcern;
    use SdkE2ETestTestStructuredRootArrayPromptMatchesTheSchemaConcern;
    use SdkE2ETestTestResumedConversationStreamReattachesInterruptSandboxForFollowUpConcern;
    use SdkE2ETestTestStreamEmitsInterruptWithoutFakeResultConcern;
    use SdkE2ETestTestAskUserValidatesAnswersBeforeClaimingCheckpointConcern;
    use SdkE2ETestTestForegroundWorktreeAgentFinalizesAndReportsAfterInterruptResumeConcern;
    use SdkE2ETestTestStructuredRetryReusesConversationSoToolsRunOnceConcern;

    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    // ──────────────────────────────────────────────────────────────
    //  Test 1: HaoCode::query() — simple one-shot
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 2: HaoCode::query() with tool use
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 3: HaoCode::query() with config options
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 4: HaoCode::stream() yields typed Message objects
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 5: HaoCode::stream() with tool use yields tool messages
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 6: HaoCode::conversation() — multi-turn
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 7: Conversation::stream() yields messages per turn
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 8: Conversation throws after close
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 9: HaoCodeConfig::make() factory
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 10: HaoCodeConfig tool filter
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 11: Message factory methods
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 12: QueryResult carries usage metadata
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 13: AbortController
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 14: SdkTool — custom tool definition
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 15: SdkTool input schema generation
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 16: StructuredResult access patterns
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 17: HaoCodeConfig with new options
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 18: Conversation.send() returns QueryResult
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 19: SDK config overrides reach StreamingClient
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 20: SDK query works with default config (no overrides)
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 21: maxBudgetUsd wires to CostTracker
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 22: systemPrompt overrides default
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 23: appendSystemPrompt reaches SettingsManager
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 24: Custom SdkTool with error handling
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 25: Multi-tool SDK query — custom + built-in tools together
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 26: Stream with multi-turn tool use collects all events
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 27: Conversation with custom tool across turns
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 28: QueryResult is Stringable in string contexts
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 29: Multiple SdkTools registered at once
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 30: Conversation getCost() and getSessionId()
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 31: SdkSkill — agent invokes a custom skill via SkillTool
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 32: SdkSkill with allowedTools restriction
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 33: Multiple skills registered at once
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 34: Skills and tools together in one query
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 35: Conversation path applies system prompt overrides
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 36: Conversation path registers SDK skills
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 37: HaoCode::structured() parses fenced JSON
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 38: HaoCode::resume() restores prior session context
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  Test 39: continueSession prefers the matching working directory
    // ──────────────────────────────────────────────────────────────

    // ══════════════════════════════════════════════════════════════

    //  Infrastructure
    // ══════════════════════════════════════════════════════════════

    // ──────────────────────────────────────────────────────────────
    //  Test: structured() schema validation (chatgpt P2)
    // ──────────────────────────────────────────────────────────────
}

final class StructuredSchemaProbeStreamWrapper
{
    public mixed $context;

    public static int $openCalls = 0;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$openCalls++;

        return false;
    }
}

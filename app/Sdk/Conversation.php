<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;

/**
 * Multi-turn conversation handle.
 *
 * Maintains a persistent AgentLoop so subsequent send() calls
 * share the same message history and session.
 *
 * @api
 */
class Conversation
{
    use ConversationConstructConcern;
    use ConversationStreamResumeInterruptConcern;
    use ConversationGetTurnCountConcern;

    private AgentLoop $loop;

    private bool $closed = false;

    private int $turnCount = 0;

    private SdkRun $run;

    private bool $snapshotRestored = false;

    private bool $operationActive = false;

    /**
     * The agent definition backing this conversation, normalized from the
     * constructor config via {@see Agent::fromConfig()}. Everything that
     * defines the agent (model, tools, prompts, permissions, sandbox,
     * headers) is owned by this object; session/resume concerns stay on
     * the Conversation itself.
     */
    private readonly Agent $agent;

    /**
     * Per-run execution options (callbacks, persistence, budget, cwd),
     * derived from the same constructor config.
     */
    private readonly RunOptions $options;
}

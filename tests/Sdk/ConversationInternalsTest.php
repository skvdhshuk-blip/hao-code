<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Sdk\SdkRun;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Session\SessionManager;
use Tests\TestCase;

/**
 * Guards the internal refactor where Conversation organizes itself around an
 * Agent definition + RunOptions instead of holding a raw HaoCodeConfig.
 */
class ConversationInternalsTest extends TestCase
{
    use ConversationInternalsTestTestConversationDerivesAgentAndOptionsFromConfigConcern;
    use ConversationInternalsTestTestTerminalStreamCleanupDoesNotClearANewStreamOperationConcern;

}

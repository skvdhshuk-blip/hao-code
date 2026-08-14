<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\HumanInterruptCoordinator;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Settings\SettingsManager;

/**
 * HaoCode SDK — programmatic access to the agent's capabilities.
 *
 * Six entry points covering the full spectrum from simple to advanced:
 *
 *   // 1. One-shot query
 *   $result = HaoCode::query('Explain this codebase');
 *   echo $result;        // Stringable
 *   echo $result->cost;  // plus metadata
 *
 *   // 2. Streaming messages
 *   foreach (HaoCode::stream('Explain PHP Fibers') as $msg) { ... }
 *
 *   // 3. Multi-turn conversation
 *   $conv = HaoCode::conversation();
 *   $conv->send('Create a User model');
 *
 *   // 4. Resume a previous session
 *   $conv = HaoCode::resume('20260407_abc123');
 *
 *   // 5. Structured output
 *   $data = HaoCode::structured('Classify this ticket', $schema);
 *   echo $data->category;
 *
 *   // 6. Custom tools
 *   HaoCode::query('Look up order #123', new HaoCodeConfig(
 *       allowedTools: ['LookupOrder'],
 *       tools: [new LookupOrderTool()],
 *   ));
 *
 * @api
 */
class HaoCode
{
    use HaoCodeQueryConcern;
    use HaoCodeContinueLatestConcern;
    use HaoCodeRunStructuredStateMachineConcern;

}

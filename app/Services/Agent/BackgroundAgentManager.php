<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

class BackgroundAgentManager
{
    use BackgroundAgentManagerConstructConcern;
    use BackgroundAgentCapacityConcern;
    use BackgroundAgentManagerMutateStateConcern;

    private const RESULT_LIMIT = 100000;

    private readonly BackgroundAgentLimits $limits;

    private readonly BackgroundAgentStateStore $stateStore;

    private readonly BackgroundAgentProcessReaper $processReaper;

    private bool $processingExitedChildren = false;
}

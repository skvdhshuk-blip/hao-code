<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Support\StateIdentifier;

class BackgroundAgentManager
{
    use BackgroundAgentManagerConstructConcern;
    use BackgroundAgentManagerMutateStateConcern;
    use BackgroundAgentManagerRegisterSignalReaperConcern;

    private const RESULT_LIMIT = 100000;

    /** @var array<int, \WeakReference> */
    private static array $signalReapers = [];

    private static bool $signalReaperInstalled = false;

    private static mixed $previousSigchldHandler = null;

    private static ?bool $previousAsyncSignals = null;

    /** @var array<int, array{id: string, token: string}> */
    private array $ownedProcesses = [];

    /** @var array<int, array{id: string, token: string}> */
    private array $exitedProcesses = [];

    private bool $reapingProcessHandles = false;

    private bool $reapAgain = false;

    private bool $processingExitedChildren = false;
}

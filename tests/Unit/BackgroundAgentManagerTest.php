<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use PHPUnit\Framework\TestCase;

class BackgroundAgentManagerTest extends TestCase
{
    use BackgroundAgentManagerTestSetUpConcern;
    use BackgroundAgentManagerTestTestSignalReaperRestoresHostHandlerAndAsyncModeConcern;

    private string $tempDir;
    private BackgroundAgentManager $manager;
}

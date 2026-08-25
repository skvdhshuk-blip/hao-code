<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Run\DurableToolExecutionCoordinator;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Session\SessionManager;

/** @internal */
final class AgentPersistence
{
    public function __construct(
        public readonly SessionManager $sessionManager,
        public readonly ?RunJournal $runJournal,
        public readonly ?DurableToolExecutionCoordinator $durableToolCoordinator,
    ) {}
}

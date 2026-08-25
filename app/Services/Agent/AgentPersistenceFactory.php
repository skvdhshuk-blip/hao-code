<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Run\DurableToolExecutionCoordinator;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Run\RunStateStoreFactory;
use HaoCode\Services\Run\ToolExecutionStoreInterface;
use HaoCode\Services\Session\SessionManager;

/** Owns the persistence policy for one agent loop. @internal */
final class AgentPersistenceFactory
{
    public function __construct(
        private readonly string $sessionPath,
        private readonly string $runStore = 'jsonl',
        private readonly ?string $runDatabasePath = null,
    ) {
        if (trim($this->sessionPath) === '') {
            throw new \InvalidArgumentException('Session storage path must be non-empty.');
        }
    }

    public function create(bool $ephemeral): AgentPersistence
    {
        $sessionManager = new SessionManager(
            persistenceEnabled: ! $ephemeral,
            sessionPath: $this->sessionPath,
        );
        if ($ephemeral) {
            return new AgentPersistence($sessionManager, null, null);
        }

        $runStore = RunStateStoreFactory::make(
            $this->runStore,
            $this->sessionPath,
            $this->runDatabasePath,
        );
        $runJournal = new RunJournal(
            $runStore,
            $runStore,
            fn (): string => $sessionManager->getSessionId(),
        );
        $coordinator = $runStore instanceof ToolExecutionStoreInterface
            ? new DurableToolExecutionCoordinator(
                $runStore,
                $runJournal,
                'worker_'.getmypid().'_'.bin2hex(random_bytes(8)),
            )
            : null;

        return new AgentPersistence($sessionManager, $runJournal, $coordinator);
    }
}

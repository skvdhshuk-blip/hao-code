<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Session\SessionManager;

trait ConversationGetTurnCountConcern
{

    /**
     * @api
     */
    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    /**
     * @api
     */
    public function getSessionId(): ?string
    {
        return $this->loop->getSessionManager()->getSessionId();
    }

    /**
     * @api
     */
    public function getCost(): float
    {
        return $this->loop->getEstimatedCost();
    }

    /**
     * @api
     */
    public function abort(): void
    {
        $this->loop->abort();
    }

    /**
     * Keep the sandbox filesystem when closing after a durable HITL interrupt.
     *
     * @internal
     */
    public function preserveSandboxOnClose(): void
    {
        $this->run->preserveSandboxOnClose();
    }

    private function beginOperation(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Conversation has been closed.');
        }
        if ($this->operationActive) {
            throw new \RuntimeException('Another conversation operation is already in progress.');
        }

        $this->operationActive = true;
    }

    private function endOperation(): void
    {
        $this->operationActive = false;
    }

    /**
     * @api
     */
    public function close(): void
    {
        if ($this->operationActive) {
            throw new \RuntimeException(
                'Cannot close a conversation while an operation is already in progress.',
            );
        }

        try {
            $this->run->close();
        } finally {
            // SdkRun closes itself in finally even when sandbox/MCP cleanup
            // reports an error; Conversation must mirror that terminal state.
            $this->closed = true;
        }
    }
}

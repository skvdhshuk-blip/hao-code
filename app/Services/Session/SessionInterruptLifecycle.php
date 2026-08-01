<?php

namespace HaoCode\Services\Session;

/**
 * Persists the durable human-interrupt state machine.
 *
 * The public SessionManager methods remain the compatibility boundary; this
 * class owns only the locked JSONL transitions and parent-chain cancellation.
 *
 * @internal
 */
final class SessionInterruptLifecycle
{
    private readonly \Closure $validateSessionId;

    private readonly \Closure $findParentLink;

    public function __construct(
        private readonly string $sessionPath,
        private readonly string $sessionId,
        private readonly bool $persistenceEnabled,
        private readonly ?string $currentWorkingDirectory,
        private readonly SessionJsonlStore $store,
        callable $validateSessionId,
        callable $findParentLink,
    ) {
        $this->validateSessionId = \Closure::fromCallable($validateSessionId);
        $this->findParentLink = \Closure::fromCallable($findParentLink);
    }

    /** @internal */
    public function recordPendingInterrupt(array $interrupt, array $checkpoint): void
    {
        if (! $this->persistenceEnabled) {
            throw new \RuntimeException('Human-in-the-loop requires a durable session.');
        }

        $entry = [
            'timestamp' => date('c'),
            'session_id' => $this->sessionId,
            'cwd' => $this->currentCwd(),
            'type' => 'interrupt_pending',
            'interrupt' => $interrupt,
            'checkpoint' => $checkpoint,
        ];
        $line = SessionJsonlStore::encodeEntryForJsonl($entry)."\n";

        $this->store->appendLineToSessionFile(
            $this->sessionPath,
            $this->sessionFilePath(),
            $line,
            'interrupt checkpoint',
        );
    }

    /** @internal */
    public function recordChildWaitInterrupt(array $interrupt, array $checkpoint): void
    {
        $interruptId = (string) ($interrupt['id'] ?? '');
        $handle = @fopen($this->sessionFilePath(), 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$this->sessionId}");
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_resolving') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot wait for a child from state {$state}.");
            }
            $this->store->appendJsonLine($handle, [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentCwd(),
                'type' => 'interrupt_pending',
                'interrupt' => $interrupt,
                'checkpoint' => $checkpoint,
            ]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function claimInterrupt(string $sessionId, string $interruptId, array $decisions): array
    {
        if (! $this->persistenceEnabled) {
            throw new \RuntimeException('Human-in-the-loop requires a durable session.');
        }

        $sessionId = ($this->validateSessionId)($sessionId);
        if (! is_dir($this->sessionPath)) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        $path = $this->pathFor($sessionId);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }

            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);

            if ($latest === null) {
                throw new \RuntimeException("Interrupt not found: {$interruptId}");
            }
            if (($latest['type'] ?? null) !== 'interrupt_pending') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'unknown'));
                throw new \RuntimeException("Interrupt {$interruptId} is already {$state}; automatic retry is disabled.");
            }

            $entry = [
                'timestamp' => date('c'),
                'session_id' => $sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentCwd(),
                'type' => 'interrupt_resolving',
                'interrupt' => $latest['interrupt'],
                'checkpoint' => $latest['checkpoint'],
                'decisions' => $decisions,
            ];
            $this->store->appendJsonLine($handle, $entry);

            return $entry;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function getInterruptState(string $sessionId, string $interruptId): array
    {
        $sessionId = ($this->validateSessionId)($sessionId);
        $path = $this->pathFor($sessionId);
        if (! is_file($path)) {
            throw new \RuntimeException("Interrupt not found: {$interruptId}");
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open session file for interrupt {$interruptId}.");
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Could not lock session file for interrupt {$interruptId}.");
            }
            // Uses fail-closed corrupt-line detection (must not roll back resolving → pending).
            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($latest === null) {
            throw new \RuntimeException("Interrupt not found: {$interruptId}");
        }

        return $latest;
    }

    /** @internal */
    public function failInterrupt(
        string $sessionId,
        string $interruptId,
        string $error,
        string $sideEffectStatus = 'unknown',
        ?array $partialResults = null,
    ): void {
        if (! $this->persistenceEnabled) {
            return;
        }

        $sessionId = ($this->validateSessionId)($sessionId);
        $path = $this->pathFor($sessionId);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }

            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);
            if ($latest === null || ($latest['type'] ?? null) !== 'interrupt_resolving') {
                return;
            }

            $this->store->appendJsonLine($handle, [
                'timestamp' => date('c'),
                'session_id' => $sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentCwd(),
                'type' => 'interrupt_failed',
                'interrupt' => $latest['interrupt'],
                'checkpoint' => $latest['checkpoint'] ?? null,
                'error' => $error,
                'side_effect_status' => in_array($sideEffectStatus, ['none', 'partial', 'unknown'], true)
                    ? $sideEffectStatus
                    : 'unknown',
                'partial_results' => $partialResults,
            ]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function recordInterruptParentLink(
        string $childSessionId,
        string $childInterruptId,
        string $parentSessionId,
        string $parentInterruptId,
        string $parentActionId,
    ): void {
        $childSessionId = ($this->validateSessionId)($childSessionId);
        $path = $this->pathFor($childSessionId);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$childSessionId}");
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $this->store->appendJsonLine($handle, [
                'timestamp' => date('c'),
                'session_id' => $childSessionId,
                'cwd' => $this->currentCwd(),
                'type' => 'interrupt_parent',
                'interrupt' => ['id' => $childInterruptId],
                'parent_session_id' => $parentSessionId,
                'parent_interrupt_id' => $parentInterruptId,
                'parent_action_id' => $parentActionId,
            ]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function resolveInterrupt(string $interruptId, array $toolResults): void
    {
        $handle = @fopen($this->sessionFilePath(), 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$this->sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_resolving') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot resolve from state {$state}.");
            }

            $this->store->appendJsonLine($handle, [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentCwd(),
                'type' => 'interrupt_resolved',
                'interrupt' => $latest['interrupt'],
                'tool_results' => $toolResults,
            ]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function cancelInterrupt(string $sessionId, string $interruptId, string $reason): void
    {
        $this->cancelInterruptChain($sessionId, $interruptId, $reason, []);
    }

    /** @param array<string, true> $visited */
    private function cancelInterruptChain(
        string $sessionId,
        string $interruptId,
        string $reason,
        array $visited,
    ): void {
        $sessionId = ($this->validateSessionId)($sessionId);
        $chainKey = $sessionId.':'.$interruptId;
        if (isset($visited[$chainKey])) {
            throw new \RuntimeException("Interrupt parent cycle detected at {$interruptId}.");
        }
        $visited[$chainKey] = true;

        $path = $this->pathFor($sessionId);
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = SessionJsonlStore::findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_cancelled'
                && ($latest['type'] ?? null) !== 'interrupt_pending') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot be cancelled from state {$state}.");
            }

            if (($latest['type'] ?? null) === 'interrupt_pending') {
                $checkpoint = is_array($latest['checkpoint'] ?? null) ? $latest['checkpoint'] : [];
                $toolResults = is_array($checkpoint['results'] ?? null) ? $checkpoint['results'] : [];
                $blocks = is_array($checkpoint['blocks'] ?? null) ? $checkpoint['blocks'] : [];
                if ($blocks === []) {
                    $blocks = $latest['interrupt']['actions'] ?? [];
                }
                foreach ($blocks as $index => $block) {
                    if (array_key_exists($index, $toolResults)) {
                        continue;
                    }
                    $actionId = is_array($block) ? (string) ($block['id'] ?? '') : '';
                    if ($actionId === '') {
                        continue;
                    }
                    $toolResults[$index] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $actionId,
                        'content' => 'Cancelled: '.$reason,
                        'is_error' => true,
                    ];
                }
                ksort($toolResults);
                $toolResults = array_values($toolResults);

                $this->store->appendJsonLine($handle, [
                    'timestamp' => date('c'),
                    'session_id' => $sessionId,
                    'cwd' => $latest['cwd'] ?? $this->currentCwd(),
                    'type' => 'interrupt_cancelled',
                    'interrupt' => $latest['interrupt'],
                    'tool_results' => $toolResults,
                    'reason' => $reason,
                ]);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $parent = ($this->findParentLink)($sessionId, $interruptId);
        if ($parent !== null) {
            $this->cancelInterruptChain(
                (string) $parent['parent_session_id'],
                (string) $parent['parent_interrupt_id'],
                $reason,
                $visited,
            );
        }
    }

    private function sessionFilePath(): string
    {
        return $this->pathFor($this->sessionId);
    }

    private function pathFor(string $sessionId): string
    {
        return $this->sessionPath.'/'.$sessionId.'.jsonl';
    }

    private function currentCwd(): ?string
    {
        return $this->currentWorkingDirectory ?? (getcwd() ?: null);
    }
}

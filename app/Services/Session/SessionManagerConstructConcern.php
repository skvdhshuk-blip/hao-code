<?php

namespace HaoCode\Services\Session;

trait SessionManagerConstructConcern
{

    public function __construct(
        private readonly bool $persistenceEnabled = true,
        string $sessionPath = '',
    )
    {
        if (trim($sessionPath) === '') {
            throw new \InvalidArgumentException('Session storage path must be injected.');
        }
        $this->sessionId = $this->generateSessionId();
        $this->sessionPath = rtrim($sessionPath, '/\\');
    }

    private function jsonlStore(): SessionJsonlStore
    {
        return $this->jsonlStore ??= new SessionJsonlStore(
            self::MAX_ENTRY_BYTES,
            self::MAX_SESSION_BYTES,
        );
    }

    private function interruptLifecycle(): SessionInterruptLifecycle
    {
        return new SessionInterruptLifecycle(
            sessionPath: $this->sessionPath,
            sessionId: $this->sessionId,
            persistenceEnabled: $this->persistenceEnabled,
            currentWorkingDirectory: $this->currentWorkingDirectory,
            store: $this->jsonlStore(),
            validateSessionId: fn (string $sessionId): string => $this->validateSessionId($sessionId),
            findParentLink: fn (string $sessionId, string $interruptId): ?array =>
                $this->findInterruptParentLink($sessionId, $interruptId),
        );
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Where this session's plan file lives while the run is in plan mode.
     *
     * @internal
     */
    public function getPlanFilePath(): string
    {
        return $this->sessionPath.'/plans/'.$this->sessionId.'.md';
    }

    /** @internal */
    public function getSessionPath(): string
    {
        return $this->sessionPath;
    }

    /**
     * 判断当前会话是否允许写入持久化存储。
     */
    public function isPersistenceEnabled(): bool
    {
        return $this->persistenceEnabled;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->recordEntry(['type' => 'session_title', 'title' => $title]);
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function switchToSession(string $sessionId, ?string $title = null): void
    {
        $this->sessionId = $this->validateSessionId($sessionId);
        $this->title = $title;
    }

    public function setCurrentWorkingDirectory(?string $cwd): void
    {
        $this->currentWorkingDirectory = $cwd;
    }

    /**
     * @return array{session_id: string, source_session_id: string, title: string}
     */
    public function branchSession(?string $customTitle = null): array
    {
        $sourceSessionId = $this->sessionId;
        $sourceEntries = $this->loadSession($sourceSessionId);

        if ($sourceEntries === []) {
            throw new \RuntimeException('No conversation to branch.');
        }

        $latestInterruptState = [];
        foreach ($sourceEntries as $entry) {
            $type = $entry['type'] ?? null;
            if (! in_array($type, [
                'interrupt_pending',
                'interrupt_resolving',
                'interrupt_resolved',
                'interrupt_cancelled',
                'interrupt_failed',
            ], true)) {
                continue;
            }
            $interruptId = is_array($entry['interrupt'] ?? null)
                ? (string) ($entry['interrupt']['id'] ?? '')
                : '';
            if ($interruptId !== '') {
                $latestInterruptState[$interruptId] = $type;
            }
        }
        foreach ($latestInterruptState as $type) {
            if (in_array($type, ['interrupt_pending', 'interrupt_resolving'], true)) {
                throw new \RuntimeException(
                    'Cannot branch a session with an unfinished human interrupt. '
                    .'Resolve or cancel the interrupt first.',
                );
            }
        }

        $branchedSessionId = $this->generateSessionId();
        $branchTitle = $this->makeUniqueBranchTitle(
            $customTitle ?: $this->deriveBranchTitleBase($sourceEntries)
        );
        $now = date('c');
        $branchedEntries = [
            [
                'timestamp' => $now,
                'session_id' => $branchedSessionId,
                'type' => 'session_title',
                'title' => $branchTitle,
            ],
            [
                'timestamp' => $now,
                'session_id' => $branchedSessionId,
                'type' => 'session_branch',
                'source_session_id' => $sourceSessionId,
            ],
        ];

        // Copy settled history only. Skip unfinished interrupt lifecycle
        // entries and parent links so branches never inherit live HITL state.
        $skipTypes = [
            'session_title',
            'session_branch',
            'interrupt_pending',
            'interrupt_resolving',
            'interrupt_failed',
            'interrupt_parent',
            'run_event',
            'run_checkpoint',
        ];
        foreach ($sourceEntries as $entry) {
            if (in_array($entry['type'] ?? null, $skipTypes, true)) {
                continue;
            }

            $entry['session_id'] = $branchedSessionId;
            $branchedEntries[] = $entry;
        }

        $this->writeSessionEntries($branchedSessionId, $branchedEntries);
        $this->switchToSession($branchedSessionId, $branchTitle);

        return [
            'session_id' => $branchedSessionId,
            'source_session_id' => $sourceSessionId,
            'title' => $branchTitle,
        ];
    }

    /**
     * Extract the stored title from a list of JSONL entries.
     */
    public static function extractTitleFromEntries(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') === 'session_title') {
                return $entry['title'] ?? null;
            }
        }

        return null;
    }

    /**
     * Record an entry to the session transcript (JSONL format).
     */
    public function recordEntry(array $entry): void
    {
        if (! $this->persistenceEnabled) {
            return;
        }

        $line = self::encodeEntryForJsonl(array_merge(
            [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $this->currentWorkingDirectory ?? (getcwd() ?: null),
            ],
            $entry
        ))."\n";

        $this->appendLineToSessionFile($line, 'session transcript');
    }

    /**
     * Record a complete turn (user message → assistant response → tool results).
     */
    public function recordTurn(array $assistantMessage, array $toolResults): void
    {
        $this->recordEntry([
            'type' => 'assistant_turn',
            'message' => $assistantMessage,
            'tool_results' => $toolResults,
        ]);
    }

    /** @internal */
    public function recordPendingInterrupt(array $interrupt, array $checkpoint): void
    {
        $this->interruptLifecycle()->recordPendingInterrupt($interrupt, $checkpoint);
    }

    /** @internal */
    public function recordChildWaitInterrupt(array $interrupt, array $checkpoint): void
    {
        $this->interruptLifecycle()->recordChildWaitInterrupt($interrupt, $checkpoint);
    }

    /** @internal */
    public function claimInterrupt(string $sessionId, string $interruptId, array $decisions): array
    {
        return $this->interruptLifecycle()->claimInterrupt($sessionId, $interruptId, $decisions);
    }

    /** @internal */
    public function getInterruptState(string $sessionId, string $interruptId): array
    {
        return $this->interruptLifecycle()->getInterruptState($sessionId, $interruptId);
    }

    /** @internal */
    public function failInterrupt(
        string $sessionId,
        string $interruptId,
        string $error,
        string $sideEffectStatus = 'unknown',
        ?array $partialResults = null,
    ): void {
        $this->interruptLifecycle()->failInterrupt(
            $sessionId,
            $interruptId,
            $error,
            $sideEffectStatus,
            $partialResults,
        );
    }

    /**
     * Canonical working directory recorded in the session transcript.
     * Prefers the first user_message cwd, then any earlier entry cwd.
     *
     * @internal
     */
    public function getSessionCanonicalCwd(string $sessionId): ?string
    {
        $entries = $this->loadSession($sessionId);
        $fallback = null;
        foreach ($entries as $entry) {
            $cwd = $entry['cwd'] ?? null;
            if (! is_string($cwd) || $cwd === '') {
                continue;
            }
            if (($entry['type'] ?? null) === 'user_message') {
                return $cwd;
            }
            $fallback ??= $cwd;
        }

        return $fallback;
    }

    /** @internal */
    public function recordInterruptParentLink(
        string $childSessionId,
        string $childInterruptId,
        string $parentSessionId,
        string $parentInterruptId,
        string $parentActionId,
    ): void {
        $this->interruptLifecycle()->recordInterruptParentLink(
            $childSessionId,
            $childInterruptId,
            $parentSessionId,
            $parentInterruptId,
            $parentActionId,
        );
    }

    /** @internal */
    public function findInterruptParentLink(string $sessionId, string $interruptId): ?array
    {
        $latest = null;
        foreach ($this->loadSession($sessionId) as $entry) {
            if (($entry['type'] ?? null) === 'interrupt_parent'
                && ($entry['interrupt']['id'] ?? null) === $interruptId) {
                $latest = $entry;
            }
        }

        return $latest;
    }

    /** @internal */
    public function resolveInterrupt(string $interruptId, array $toolResults): void
    {
        $this->interruptLifecycle()->resolveInterrupt($interruptId, $toolResults);
    }

    /** @internal */
    public function cancelInterrupt(string $sessionId, string $interruptId, string $reason): void
    {
        $this->interruptLifecycle()->cancelInterrupt($sessionId, $interruptId, $reason);
    }

    public function recordUserMessage(string $text): void
    {
        $this->recordEntry([
            'type' => 'user_message',
            'content' => $text,
        ]);
    }

    /**
     * Load a previous session from transcript.
     *
     * Returns the parsed entries. When the caller passed a partial id that
     * matched via glob, the resolved canonical id is exposed via
     * {@see findSessionId()} (and is what the session manager will write to
     * going forward, so reads and writes stay consistent). Multiple glob
     * matches throw — callers must disambiguate.
     */
    public function loadSession(string $sessionId): array
    {
        $sessionId = $this->validateSessionId($sessionId);

        // Try exact match first (file format: {sessionId}.jsonl)
        $exactPath = $this->sessionPath.'/'.$sessionId.'.jsonl';
        if (file_exists($exactPath)) {
            $this->lastResolvedSessionId = $sessionId;

            return $this->readEntriesFromPath($exactPath);
        }

        // Fallback to glob for partial ID matching — but require a UNIQUE
        // hit. Previously the first match silently won, which let a short
        // prefix like "2026-07" load 2026-07-20_120000_abcd.jsonl while
        // later writes landed in 2026-07.jsonl (read/write split-brain).
        // chatgpt 3rd review #9.
        $matches = glob($this->sessionPath.'/'.$sessionId.'*.jsonl') ?: [];
        if ($matches === []) {
            return [];
        }
        if (count($matches) > 1) {
            $names = array_map(static fn (string $p): string => basename($p), $matches);

            throw new \RuntimeException(
                'Ambiguous session id "'.$sessionId.'" matches '
                .count($matches).' sessions: '.implode(', ', array_slice($names, 0, 5))
                .(count($names) > 5 ? ', ...' : '')
                .'. Provide the full session id.',
            );
        }

        $canonical = basename($matches[0], '.jsonl');
        $this->lastResolvedSessionId = $canonical;

        return $this->readEntriesFromPath($matches[0]);
    }

    /**
     * Resolve a (possibly partial) session id to its canonical form.
     *
     * Returns the canonical id (basename without .jsonl) when exactly one
     * session matches, null when nothing matches, and throws when multiple
     * sessions match. Exposed so callers that need to switch the session
     * manager to the right id can do so without re-running the glob.
     */
    public function findSessionId(string $partial): ?string
    {
        $partial = $this->validateSessionId($partial);

        if (file_exists($this->sessionPath.'/'.$partial.'.jsonl')) {
            return $partial;
        }

        $matches = glob($this->sessionPath.'/'.$partial.'*.jsonl') ?: [];
        if ($matches === []) {
            return null;
        }
        if (count($matches) > 1) {
            $names = array_map(static fn (string $p): string => basename($p), $matches);

            throw new \RuntimeException(
                'Ambiguous session id "'.$partial.'" matches '
                .count($matches).' sessions: '.implode(', ', array_slice($names, 0, 5))
                .(count($names) > 5 ? ', ...' : '')
                .'. Provide the full session id.',
            );
        }

        return basename($matches[0], '.jsonl');
    }

    /**
     * The canonical id resolved by the most recent loadSession() call, or
     * null when loadSession() has not been called (or the last call did not
     * match any session). Callers that previously switched to the user-
     * supplied partial id should switch to this instead to avoid the
     * read-A-write-B split-brain.
     */
    public function getLastResolvedSessionId(): ?string
    {
        return $this->lastResolvedSessionId;
    }

    private function readEntriesFromPath(string $path): array
    {
        return $this->jsonlStore()->readEntries($path);
    }

    private function getFilePath(): string
    {
        return $this->sessionPath.'/'.$this->sessionId.'.jsonl';
    }

    /**
     * Reject session IDs that could escape the session directory.
     *
     * Session IDs are persisted as `{sessionPath}/{$sessionId}.jsonl` and also
     * fed to glob(). Anything containing path separators, `..`, NUL bytes, or
     * glob metacharacters can read or write outside the session directory, so
     * every external entry point must funnel through this guard. The format
     * matches {@see generateSessionId()} (`Y-m-d_His_hex`) but is permissive
     * enough for legacy/test IDs like `nonexistent_session_xyz`.
     */
    private function validateSessionId(string $sessionId): string
    {
        if ($sessionId === '' || strlen($sessionId) > 128
            || preg_match('/[^A-Za-z0-9_-]/', $sessionId) === 1
        ) {
            throw new \InvalidArgumentException(
                'Invalid session id: must be 1-128 characters of [A-Za-z0-9_-].',
            );
        }

        return $sessionId;
    }

    /**
     * Encode one JSONL entry, degrading gracefully instead of losing it.
     * Tool outputs and checkpoint history can carry invalid UTF-8 (binary or
     * non-UTF-8 file contents) or non-finite doubles — both make json_encode
     * fail. Sanitize and retry, then fall back to partial output; writing a
     * checkpoint or transcript line must never kill or silently corrupt a run.
     */
    private static function encodeEntryForJsonl(array $entry): string
    {
        return SessionJsonlStore::encodeEntryForJsonl($entry);
    }
}

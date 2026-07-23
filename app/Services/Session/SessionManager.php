<?php

namespace HaoCode\Services\Session;

class SessionManager
{
    private string $sessionId;

    private string $sessionPath;

    private ?string $title = null;

    private ?string $currentWorkingDirectory = null;

    /**
     * Canonical id resolved by the most recent loadSession() call. Null until
     * a session is loaded. Lets callers switch the active session to the
     * canonical id rather than the user-supplied partial (chatgpt #9).
     */
    private ?string $lastResolvedSessionId = null;

    public function __construct(
        private readonly bool $persistenceEnabled = true,
    )
    {
        $this->sessionId = $this->generateSessionId();
        $this->sessionPath = \HaoCode\Support\Runtime\SdkRuntime::config(
            'haocode.session_path',
            \HaoCode\Support\Runtime\SdkRuntime::storagePath('app/haocode/sessions'),
        );
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
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

        foreach ($sourceEntries as $entry) {
            if (in_array($entry['type'] ?? null, ['session_title', 'session_branch'], true)) {
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

        if (! is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }

        $line = self::encodeEntryForJsonl(array_merge(
            [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $this->currentWorkingDirectory ?? (getcwd() ?: null),
            ],
            $entry
        ))."\n";

        file_put_contents($this->getFilePath(), $line, FILE_APPEND | LOCK_EX);
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

    /**
     * Persist a pending human interrupt and the minimum checkpoint required to resume it.
     *
     * @internal
     */
    public function recordPendingInterrupt(array $interrupt, array $checkpoint): void
    {
        if (! $this->persistenceEnabled) {
            throw new \RuntimeException('Human-in-the-loop requires a durable session.');
        }

        // Unlike recordEntry() (which is best-effort for ordinary transcript
        // lines), an interrupt checkpoint is the SDK's promise that the call
        // can be resumed. A silent write failure would let the caller hand
        // back a HumanInterrupt that points at a non-existent checkpoint, so
        // the next resume() would crash with "Interrupt not found". Use a
        // durable write path: exclusive lock, check every I/O return value,
        // and throw on failure so the caller sees the problem immediately
        // rather than after raising the interrupt (chatgpt 3rd review #8).
        if (! is_dir($this->sessionPath)) {
            if (! @mkdir($this->sessionPath, 0700, true) && ! is_dir($this->sessionPath)) {
                throw new \RuntimeException(
                    'Could not create session directory for interrupt checkpoint: '.$this->sessionPath,
                );
            }
        }

        $entry = array_merge(
            [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $this->currentWorkingDirectory ?? (getcwd() ?: null),
                'type' => 'interrupt_pending',
                'interrupt' => $interrupt,
                'checkpoint' => $checkpoint,
            ],
        );
        $line = self::encodeEntryForJsonl($entry)."\n";

        $handle = @fopen($this->getFilePath(), 'a');
        if ($handle === false) {
            throw new \RuntimeException(
                'Could not open session file for interrupt checkpoint: '.$this->getFilePath(),
            );
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session file for interrupt checkpoint.');
            }
            $written = fwrite($handle, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new \RuntimeException('Could not write interrupt checkpoint to session file.');
            }
            if (! fflush($handle)) {
                throw new \RuntimeException('Could not flush interrupt checkpoint to disk.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function recordChildWaitInterrupt(array $interrupt, array $checkpoint): void
    {
        $interruptId = (string) ($interrupt['id'] ?? '');
        $handle = @fopen($this->getFilePath(), 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$this->sessionId}");
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = $this->findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_resolving') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot wait for a child from state {$state}.");
            }
            $this->appendJsonLine($handle, [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentWorkingDirectory ?? (getcwd() ?: null),
                'type' => 'interrupt_pending',
                'interrupt' => $interrupt,
                'checkpoint' => $checkpoint,
            ]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Atomically move a pending interrupt to resolving and return its checkpoint.
     * Once claimed, it is never automatically retried because tool side effects may
     * already have occurred before a process failure.
     *
     * @internal
     */
    public function claimInterrupt(string $sessionId, string $interruptId, array $decisions): array
    {
        if (! $this->persistenceEnabled) {
            throw new \RuntimeException('Human-in-the-loop requires a durable session.');
        }

        $sessionId = $this->validateSessionId($sessionId);

        if (! is_dir($this->sessionPath)) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        $path = $this->sessionPath.'/'.$sessionId.'.jsonl';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }

            $latest = $this->findLatestInterruptEntry($handle, $interruptId);

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
                'cwd' => $latest['cwd'] ?? $this->currentWorkingDirectory ?? (getcwd() ?: null),
                'type' => 'interrupt_resolving',
                'interrupt' => $latest['interrupt'],
                'checkpoint' => $latest['checkpoint'],
                'decisions' => $decisions,
            ];
            $this->appendJsonLine($handle, $entry);

            return $entry;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @internal */
    public function getInterruptState(string $sessionId, string $interruptId): array
    {
        $latest = null;
        foreach ($this->loadSession($sessionId) as $entry) {
            if (in_array($entry['type'] ?? null, ['interrupt_pending', 'interrupt_resolving', 'interrupt_resolved', 'interrupt_cancelled'], true)
                && ($entry['interrupt']['id'] ?? null) === $interruptId) {
                $latest = $entry;
            }
        }
        if ($latest === null) {
            throw new \RuntimeException("Interrupt not found: {$interruptId}");
        }

        return $latest;
    }

    /** @internal */
    public function recordInterruptParentLink(
        string $childSessionId,
        string $childInterruptId,
        string $parentSessionId,
        string $parentInterruptId,
        string $parentActionId,
    ): void {
        $this->appendEntryToSession($childSessionId, [
            'type' => 'interrupt_parent',
            'interrupt' => ['id' => $childInterruptId],
            'parent_session_id' => $parentSessionId,
            'parent_interrupt_id' => $parentInterruptId,
            'parent_action_id' => $parentActionId,
        ]);
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
        $path = $this->getFilePath();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$this->sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = $this->findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_resolving') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot resolve from state {$state}.");
            }

            $entry = [
                'timestamp' => date('c'),
                'session_id' => $this->sessionId,
                'cwd' => $latest['cwd'] ?? $this->currentWorkingDirectory ?? (getcwd() ?: null),
                'type' => 'interrupt_resolved',
                'interrupt' => $latest['interrupt'],
                'tool_results' => $toolResults,
            ];
            $this->appendJsonLine($handle, $entry);
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

    /**
     * @param array<string, true> $visited
     */
    private function cancelInterruptChain(
        string $sessionId,
        string $interruptId,
        string $reason,
        array $visited,
    ): void
    {
        $sessionId = $this->validateSessionId($sessionId);
        $chainKey = $sessionId.':'.$interruptId;
        if (isset($visited[$chainKey])) {
            throw new \RuntimeException("Interrupt parent cycle detected at {$interruptId}.");
        }
        $visited[$chainKey] = true;

        $path = $this->sessionPath.'/'.$sessionId.'.jsonl';
        $handle = @fopen($path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $latest = $this->findLatestInterruptEntry($handle, $interruptId);
            if (($latest['type'] ?? null) !== 'interrupt_cancelled'
                && ($latest['type'] ?? null) !== 'interrupt_pending') {
                $state = str_replace('interrupt_', '', (string) ($latest['type'] ?? 'missing'));
                throw new \RuntimeException("Interrupt {$interruptId} cannot be cancelled from state {$state}.");
            }

            if (($latest['type'] ?? null) === 'interrupt_pending') {
                $toolResults = [];
                foreach ($latest['interrupt']['actions'] ?? [] as $action) {
                    $actionId = is_array($action) ? (string) ($action['id'] ?? '') : '';
                    if ($actionId === '') {
                        continue;
                    }
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $actionId,
                        'content' => 'Cancelled: '.$reason,
                        'is_error' => true,
                    ];
                }

                $this->appendJsonLine($handle, [
                    'timestamp' => date('c'),
                    'session_id' => $sessionId,
                    'cwd' => $latest['cwd'] ?? $this->currentWorkingDirectory ?? (getcwd() ?: null),
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

        $parent = $this->findInterruptParentLink($sessionId, $interruptId);
        if ($parent !== null) {
            $this->cancelInterruptChain(
                (string) $parent['parent_session_id'],
                (string) $parent['parent_interrupt_id'],
                $reason,
                $visited,
            );
        }
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
        $lines = file($path);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            if (trim($line)) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }
        }

        return $entries;
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

    /** @param resource $handle */
    private function findLatestInterruptEntry($handle, string $interruptId): ?array
    {
        rewind($handle);
        $latest = null;
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (is_array($entry)
                && in_array($entry['type'] ?? null, ['interrupt_pending', 'interrupt_resolving', 'interrupt_resolved', 'interrupt_cancelled'], true)
                && ($entry['interrupt']['id'] ?? null) === $interruptId) {
                $latest = $entry;
            }
        }

        return $latest;
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
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(self::sanitizeForJson($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($encoded === false) {
            $encoded = json_encode($entry, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($encoded === false) {
            throw new \RuntimeException('Could not serialize session interrupt checkpoint.');
        }

        return $encoded;
    }

    /** @param resource $handle */
    private function appendJsonLine($handle, array $entry): void
    {
        $encoded = self::encodeEntryForJsonl($entry);
        fseek($handle, 0, SEEK_END);
        if (fwrite($handle, $encoded."\n") === false || ! fflush($handle)) {
            throw new \RuntimeException('Could not persist session interrupt checkpoint.');
        }
    }

    /**
     * Recursively scrub invalid UTF-8 byte sequences and non-finite doubles
     * so json_encode cannot fail on tool payloads.
     */
    private static function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('//u', $value) === 1) {
                return $value;
            }
            $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $scrubbed !== false ? $scrubbed : '';
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[self::sanitizeForJson($key)] = self::sanitizeForJson($item);
            }

            return $sanitized;
        }

        return $value;
    }

    private function appendEntryToSession(string $sessionId, array $entry): void
    {
        $sessionId = $this->validateSessionId($sessionId);
        $path = $this->sessionPath.'/'.$sessionId.'.jsonl';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Session not found: {$sessionId}");
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock session interrupt checkpoint.');
            }
            $this->appendJsonLine($handle, array_merge([
                'timestamp' => date('c'),
                'session_id' => $sessionId,
                'cwd' => $this->currentWorkingDirectory ?? (getcwd() ?: null),
            ], $entry));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function findMostRecentSessionId(?string $cwd = null): ?string
    {
        $files = glob($this->sessionPath.'/*.jsonl') ?: [];
        if ($files === []) {
            return null;
        }

        $candidates = [];
        foreach ($files as $file) {
            $entries = $this->loadSession(basename($file, '.jsonl'));
            if ($entries === []) {
                continue;
            }

            $latestTimestamp = null;
            $matchesCwd = false;
            foreach ($entries as $entry) {
                $timestamp = $entry['timestamp'] ?? null;
                if (is_string($timestamp)) {
                    $latestTimestamp = $timestamp;
                }

                if ($cwd !== null && is_string($entry['cwd'] ?? null) && $entry['cwd'] === $cwd) {
                    $matchesCwd = true;
                }
            }

            $candidates[] = [
                'session_id' => basename($file, '.jsonl'),
                'timestamp' => $latestTimestamp ?? date('c', filemtime($file) ?: time()),
                'cwd_match' => $matchesCwd,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['cwd_match'] !== $right['cwd_match']) {
                return $left['cwd_match'] ? -1 : 1;
            }

            return strcmp($right['timestamp'], $left['timestamp']);
        });

        return $candidates[0]['session_id'] ?? null;
    }

    private function generateSessionId(): string
    {
        return date('Y-m-d_His').'_'.bin2hex(random_bytes(4));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function deriveBranchTitleBase(array $entries): string
    {
        $existingTitle = $this->title ?? self::extractTitleFromEntries($entries);
        if (is_string($existingTitle) && trim($existingTitle) !== '') {
            return trim($existingTitle);
        }

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'user_message') {
                continue;
            }

            $content = trim((string) ($entry['content'] ?? ''));
            if ($content !== '') {
                $singleLine = preg_replace('/\s+/', ' ', $content) ?: $content;

                return mb_substr($singleLine, 0, 100);
            }
        }

        return 'Branched conversation';
    }

    private function makeUniqueBranchTitle(string $baseTitle): string
    {
        $baseTitle = trim($baseTitle);
        $baseTitle = $baseTitle !== '' ? $baseTitle : 'Branched conversation';
        $candidate = "{$baseTitle} (Branch)";
        $existingTitles = $this->existingTitles();

        if (! in_array($candidate, $existingTitles, true)) {
            return $candidate;
        }

        $suffix = 2;
        while (in_array("{$baseTitle} (Branch {$suffix})", $existingTitles, true)) {
            $suffix++;
        }

        return "{$baseTitle} (Branch {$suffix})";
    }

    /**
     * @return array<int, string>
     */
    private function existingTitles(): array
    {
        $titles = [];

        foreach (glob($this->sessionPath.'/*.jsonl') ?: [] as $file) {
            $title = self::extractTitleFromEntries($this->loadSession(basename($file, '.jsonl')));
            if (is_string($title) && trim($title) !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeSessionEntries(string $sessionId, array $entries): void
    {
        $sessionId = $this->validateSessionId($sessionId);

        if (! is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }

        $lines = array_map(function (array $entry) use ($sessionId): string {
            $entry['session_id'] = $sessionId;
            $entry['timestamp'] = (string) ($entry['timestamp'] ?? date('c'));
            $entry['cwd'] = $entry['cwd'] ?? (getcwd() ?: null);

            return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $entries);

        file_put_contents($this->sessionPath.'/'.$sessionId.'.jsonl', implode("\n", $lines)."\n");
    }
}

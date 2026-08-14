<?php

namespace HaoCode\Services\Session;

trait SessionManagerAppendLineToSessionFileConcern
{

    private function appendLineToSessionFile(string $line, string $purpose): void
    {
        $this->jsonlStore()->appendLineToSessionFile(
            $this->sessionPath,
            $this->getFilePath(),
            $line,
            $purpose,
        );
    }

    public function findMostRecentSessionId(?string $cwd = null): ?string
    {
        $files = glob($this->sessionPath.'/*.jsonl') ?: [];
        if ($files === []) {
            return null;
        }

        $candidates = [];
        foreach ($files as $file) {
            $header = $this->readSessionHeader($file);
            if ($header === null) {
                continue;
            }

            $candidates[] = [
                'session_id' => basename($file, '.jsonl'),
                // Appends update mtime, so it is the cheap and more accurate
                // recency signal. The header timestamp is only a deterministic
                // tie-breaker for files written within the same filesystem tick.
                'mtime' => @filemtime($file) ?: 0,
                'timestamp' => is_string($header['timestamp'] ?? null) ? $header['timestamp'] : '',
                // The common no-cwd path stays header-only. When continueLatest
                // supplies a cwd, stream the transcript line by line so legacy
                // worktree sessions that changed cwd later keep the old match
                // semantics without materializing the full JSONL document.
                'cwd_match' => $cwd !== null && $this->sessionContainsCwd($file, $cwd),
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['cwd_match'] !== $right['cwd_match']) {
                return $left['cwd_match'] ? -1 : 1;
            }

            if ($left['mtime'] !== $right['mtime']) {
                return $right['mtime'] <=> $left['mtime'];
            }

            return strcmp($right['timestamp'], $left['timestamp']);
        });

        return $candidates[0]['session_id'] ?? null;
    }

    /**
     * Read only the first JSONL record used for session selection.
     *
     * The complete transcript remains the responsibility of loadSession().
     * This path is called by continueMostRecent(), where parsing every large
     * transcript just to sort candidates creates an avoidable O(total bytes)
     * cost.
     *
     * @return array<string, mixed>|null
     */
    private function readSessionHeader(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return null;
            }

            $line = fgets($handle, self::SESSION_HEADER_BYTES + 1);
            if ($line === false || trim($line) === '') {
                return null;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException(
                    'Session '.basename($path, '.jsonl').' contains invalid JSON in its header: '
                    .json_last_error_msg(),
                );
            }

            return $decoded;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function sessionContainsCwd(string $path, string $cwd): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return false;
            }

            $sessionId = basename($path, '.jsonl');
            $lineNumber = 0;
            $matches = false;
            while (($line = fgets($handle, self::MAX_ENTRY_BYTES + 1)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    throw new \RuntimeException(
                        "Session {$sessionId} contains invalid JSON on line {$lineNumber}: "
                        .json_last_error_msg(),
                    );
                }
                if (($decoded['cwd'] ?? null) === $cwd) {
                    $matches = true;
                }
            }

            return $matches;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
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

            $content = $this->extractTextContent($entry['content'] ?? '');
            if ($content !== '') {
                $singleLine = preg_replace('/\s+/', ' ', $content) ?: $content;

                return mb_substr($singleLine, 0, 100);
            }
        }

        return 'Branched conversation';
    }

    private function extractTextContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $texts[] = $block;
                continue;
            }
            if (is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return trim(implode(' ', $texts));
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

        if (! is_dir($this->sessionPath)
            && ! @mkdir($this->sessionPath, 0700, true)
            && ! is_dir($this->sessionPath)) {
            throw new \RuntimeException(
                "Could not create session directory for branched transcript: {$this->sessionPath}",
            );
        }

        $lines = array_map(function (array $entry) use ($sessionId): string {
            $entry['session_id'] = $sessionId;
            $entry['timestamp'] = (string) ($entry['timestamp'] ?? date('c'));
            $entry['cwd'] = $entry['cwd'] ?? (getcwd() ?: null);

            $line = self::encodeEntryForJsonl($entry)."\n";
            if (strlen($line) > self::MAX_ENTRY_BYTES) {
                throw new \RuntimeException(
                    'Session entry exceeds the 32 MiB persistence limit.',
                );
            }

            return $line;
        }, $entries);
        $contents = implode('', $lines);
        if (strlen($contents) > self::MAX_SESSION_BYTES) {
            throw new \RuntimeException(
                'Session transcript exceeds the 128 MiB persistence limit.',
            );
        }

        $destination = $this->sessionPath.'/'.$sessionId.'.jsonl';
        $temporary = tempnam($this->sessionPath, '.branch-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not create a temporary branched session file.');
        }

        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);
            throw new \RuntimeException('Could not open the temporary branched session file.');
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Could not write the branched session transcript.');
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new \RuntimeException('Could not flush the branched session transcript.');
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temporary);
            throw $e;
        }
        fclose($handle);
        @chmod($temporary, 0600);

        if (! @rename($temporary, $destination)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not atomically publish the branched session transcript.');
        }
    }
}

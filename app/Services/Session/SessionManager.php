<?php

namespace HaoCode\Services\Session;

class SessionManager
{
    use SessionManagerConstructConcern;
    use SessionManagerAppendLineToSessionFileConcern;

    private const MAX_ENTRY_BYTES = 32 * 1024 * 1024;

    private const MAX_SESSION_BYTES = 128 * 1024 * 1024;

    /** Only the first JSONL record is needed to select a recent session. */
    private const SESSION_HEADER_BYTES = 32 * 1024 * 1024;

    private ?SessionJsonlStore $jsonlStore = null;

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
}

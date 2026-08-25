<?php

namespace Tests\Unit;

use Tests\TestCase;

/** Test fixture that turns the configured test path into an explicit constructor dependency. */
final class SessionManager extends \HaoCode\Services\Session\SessionManager
{
    public function __construct(bool $persistenceEnabled = true, string $sessionPath = '')
    {
        parent::__construct(
            $persistenceEnabled,
            $sessionPath !== '' ? $sessionPath : config('haocode.session_path'),
        );
    }
}

class SessionManagerTest extends TestCase
{
    use SessionManagerTestTestPersistenceCanBeDisabledForEphemeralQueriesConcern;
    use SessionManagerTestTestRecordTurnStoresAssistantMessageConcern;


    private string $tmpDir;

    // ─── getSessionId ─────────────────────────────────────────────────────

    // ─── title ────────────────────────────────────────────────────────────

    // ─── extractTitleFromEntries ──────────────────────────────────────────

    // ─── recordEntry / loadSession ────────────────────────────────────────

    // ─── partial ID resolution (chatgpt 3rd review #9) ─────────────────

    // ─── interrupt checkpoint durable persistence (chatgpt 3rd review #8) ──

    // ─── recordTurn ───────────────────────────────────────────────────────

    // ─── setTitle records entry ───────────────────────────────────────────
}

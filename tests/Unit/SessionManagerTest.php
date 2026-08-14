<?php

namespace Tests\Unit;

use HaoCode\Services\Session\SessionManager;
use Tests\TestCase;

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

<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebFetchToolTest extends TestCase
{
    use WebFetchToolTestSetUpConcern;
    use WebFetchToolTestTestByteCapAbortsOversizedResponseConcern;

    private WebFetchTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    // ─── name / description / isReadOnly ─────────────────────────────────

    // ─── htmlToText ───────────────────────────────────────────────────────

    // ─── call — network failure ───────────────────────────────────────────

    // ─── format parameter distinguishes text vs markdown ──────────────────

    // ─── cache isolation across security policies ─────────────────────────

    // ─── redirect resolution per RFC 3986 reference types ─────────────────

    // ─── byte cap is enforced ─────────────────────────────────────────────

    // ─── DNS pinning: HttpClient receives a hostname → checked IP map ────
}

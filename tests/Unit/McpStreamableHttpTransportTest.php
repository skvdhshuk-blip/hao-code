<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpSseDecoder;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpStreamableHttpTransportTest extends TestCase
{
    use McpStreamableHttpTransportTestTestSseDecoderHandlesChunkBoundariesCrlfMultilineDataAndRetryConcern;
    use McpStreamableHttpTransportTestTestListToolsFollowsNextCursorPaginationConcern;

}

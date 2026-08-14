<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebSearchTool extends BaseTool
{
    use WebSearchToolSetClientConcern;
    use WebSearchToolFilterResultsConcern;

    private const STATUS_SUCCESS_WITH_RESULTS = 'success_with_results';

    private const STATUS_SUCCESS_EMPTY = 'success_empty';

    private const STATUS_TRANSPORT_ERROR = 'transport_error';

    private const STATUS_HTTP_ERROR = 'http_error';

    private const STATUS_PARSE_ERROR = 'parse_error';

    /**
     * Cap response body reads so a hostile or broken search backend cannot
     * exhaust memory. 2 MiB comfortably fits a result page.
     */
    private const MAX_RESPONSE_BYTES = 2_097_152;

    private ?HttpClientInterface $client = null;
}

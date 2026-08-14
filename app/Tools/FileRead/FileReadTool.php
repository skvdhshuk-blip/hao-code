<?php

namespace HaoCode\Tools\FileRead;

use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\BoundedTextFileReader;
use HaoCode\Support\Filesystem\FileContentTypeDetector;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class FileReadTool extends BaseTool
{
    use FileReadToolNameConcern;
    use FileReadToolRunProcessWithOutputCapConcern;

    private const MAX_PDF_OUTPUT_BYTES = 100_000;
    private const PDF_TIMEOUT_SECONDS = 10.0;
    private const MAX_NOTEBOOK_BYTES = 8_388_608; // 8 MiB JSON input cap
    private const MAX_NOTEBOOK_OUTPUT_BYTES = 100_000;
}

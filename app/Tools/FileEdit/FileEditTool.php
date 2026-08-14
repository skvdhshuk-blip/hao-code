<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class FileEditTool extends BaseTool
{
    use FileEditToolNameConcern;
    use FileEditToolBackfillObservableInputConcern;

    private const MAX_EDIT_STRING_BYTES = 512_000;
    private const MAX_IN_MEMORY_EDIT_BYTES = 1_000_000;
    private const STREAM_CHUNK_BYTES = 64 * 1024;
}

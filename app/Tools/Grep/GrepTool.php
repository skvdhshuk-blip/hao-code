<?php

namespace HaoCode\Tools\Grep;

use HaoCode\Support\Filesystem\GitignoreMatcher;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class GrepTool extends BaseTool
{
    use GrepToolNameConcern;
    use GrepToolRunRipgrepStreamingConcern;
    use GrepToolGrepWithPhpConcern;
    use GrepToolAddLimitedEntryConcern;

    private const MAX_VISITED_FILES = 20_000;
    private const MAX_LINE_BYTES = 1_000_000;
    private const PHP_FALLBACK_TIMEOUT_SECONDS = 10.0;
    private const MAX_PATTERN_BYTES = 16_384;
    private const MAX_GLOB_BYTES = 512;
    private const MAX_CONTEXT_LINES = 1_000;
    private const MAX_HEAD_LIMIT = 1_000;
    private const MAX_OFFSET = 100_000;
    private const PHP_FALLBACK_OUTPUT_MAX = 1_000_000;
    private const RIPGREP_TIMEOUT_SECONDS = 10.0;
    private const RIPGREP_STDERR_MAX = 32_000;
    private const RIPGREP_OUTPUT_MAX = 1_000_000;
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '.claude/worktrees',
        'node_modules',
        'vendor',
    ];
}

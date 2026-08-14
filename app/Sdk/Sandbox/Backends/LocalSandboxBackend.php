<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/** @api */
final class LocalSandboxBackend implements SandboxBackendInterface, RevisionAwareSandboxBackendInterface
{
    use LocalSandboxBackendConstructConcern;
    use LocalSandboxBackendToRemotePathConcern;

    private const MAX_GLOB_RESULTS = 100;
    private const MAX_VISITED_FILES = 20_000;
    private const MAX_PATTERN_LENGTH = 512;
    private const MAX_BRACE_EXPANSIONS = 256;
    private const MAX_TEXT_LINE_BYTES = 1_000_000;
    private const MAX_EXEC_OUTPUT_BYTES = 100_000;
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '.claude/worktrees',
        'node_modules',
        'vendor',
    ];

    private string $root;
    private bool $ownsRoot;
    private bool $preserveOnClose = false;
}

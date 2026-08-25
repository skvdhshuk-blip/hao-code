<?php

namespace HaoCode\Tools\Worktree;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class EnterWorktreeTool extends BaseTool
{
    private const MAX_GITIGNORE_BYTES = 1_000_000;

    public function name(): string
    {
        return 'EnterWorktree';
    }

    public function description(): string
    {
        return 'Creates an isolated git worktree and switches the current session into it. Only use when the user explicitly mentions "worktree".';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional name for the worktree. If not provided, a random name is generated.',
                ],
            ],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'] ?? null;
        $originalDirectory = realpath($context->workingDirectory) ?: $context->workingDirectory;

        // Check if we're in a git repo
        $gitCheck = $this->git($originalDirectory, ['rev-parse', '--is-inside-work-tree']);
        if (trim($gitCheck['stdout']) !== 'true') {
            return ToolResult::error('Not inside a git repository. Worktrees require a git repo.');
        }

        $rootResult = $this->git($originalDirectory, ['rev-parse', '--show-toplevel']);
        $repoRoot = trim($rootResult['stdout']);
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return ToolResult::error('Could not resolve the repository root for this worktree.');
        }
        $repoRoot = realpath($repoRoot) ?: $repoRoot;

        // Check if already in a linked worktree (git-dir differs from git-common-dir)
        $gitDir = $this->resolveGitPath(
            $originalDirectory,
            trim($this->git($originalDirectory, ['rev-parse', '--git-dir'])['stdout']),
        );
        $commonDir = $this->resolveGitPath(
            $originalDirectory,
            trim($this->git($originalDirectory, ['rev-parse', '--git-common-dir'])['stdout']),
        );
        if ($gitDir === null || $commonDir === null) {
            return ToolResult::error('Could not resolve the Git worktree directories.');
        }
        if (! $this->samePath($gitDir, $commonDir)) {
            return ToolResult::error('Already in a worktree session.');
        }

        // Generate name if not provided
        if (!$name) {
            $name = 'worktree_' . bin2hex(random_bytes(4));
        }

        // Sanitize name
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
        if ($name === '') {
            $name = 'worktree_' . bin2hex(random_bytes(4));
        }
        if (mb_strlen($name) > 64) {
            $name = mb_substr($name, 0, 64);
        }

        $gitignore = $repoRoot . DIRECTORY_SEPARATOR . '.gitignore';
        if (is_link($gitignore)) {
            return ToolResult::error('Refusing to update .gitignore because it is a symlink.');
        }

        // Create .claude/worktrees directory
        $claudeDir = $repoRoot . DIRECTORY_SEPARATOR . '.claude';
        if (is_link($claudeDir)) {
            return ToolResult::error('Refusing to create worktree: .claude is a symlink.');
        }
        $worktreeBase = $claudeDir . '/worktrees';
        if (is_link($worktreeBase)) {
            return ToolResult::error('Refusing to create worktree: .claude/worktrees is a symlink.');
        }
        if (!is_dir($worktreeBase)) {
            if (! mkdir($worktreeBase, 0755, true)) {
                return ToolResult::error("Failed to create worktree directory: {$worktreeBase}");
            }
        }
        $resolvedClaudeDir = realpath($claudeDir);
        $resolvedWorktreeBase = realpath($worktreeBase);
        if (
            $resolvedClaudeDir === false
            || $resolvedWorktreeBase === false
            || ! str_starts_with(
                rtrim($resolvedWorktreeBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                rtrim($resolvedClaudeDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            )
        ) {
            return ToolResult::error('Refusing to create worktree outside the repository .claude directory.');
        }

        $worktreePath = $worktreeBase . DIRECTORY_SEPARATOR . $name;

        // Check if worktree already exists
        if (is_dir($worktreePath)) {
            return ToolResult::error("Worktree already exists: {$name}");
        }

        // Create worktree from HEAD
        $branchName = 'worktree-' . $name;
        $created = $this->git($repoRoot, [
            '-c', 'core.hooksPath='.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'),
            'worktree', 'add', '-b', $branchName, $worktreePath, 'HEAD',
        ]);

        if ($created['exitCode'] !== 0 || ! is_dir($worktreePath)) {
            return ToolResult::error("Failed to create worktree: {$created['stderr']}{$created['stdout']}");
        }

        $gitignoreWarning = null;

        // Add .claude/worktrees to .gitignore if not already
        $gitignoreContent = $this->readGitignore($gitignore);
        if ($gitignoreContent === null) {
            $gitignoreWarning = 'Warning: failed to read .gitignore; .claude/worktrees was not added.';
        } elseif (!str_contains($gitignoreContent, '.claude/worktrees')) {
            $expectedRevision = file_exists($gitignore) ? FileRevision::capture($gitignore) : null;
            if (file_exists($gitignore) && $expectedRevision === null) {
                $gitignoreWarning = 'Warning: failed to capture .gitignore revision; .claude/worktrees was not added.';
            } else {
                try {
                    (new AtomicFileWriter())->write(
                        $gitignore,
                        rtrim($gitignoreContent, "\r\n")."\n.claude/worktrees\n",
                        $expectedRevision,
                    );
                } catch (\Throwable $e) {
                    $gitignoreWarning = "Warning: failed to update .gitignore: {$e->getMessage()}";
                }
            }
        }
        $resolvedWorktree = realpath($worktreePath) ?: $worktreePath;
        $context->rememberWorktreeOriginalDirectory($originalDirectory);
        $context->setWorkingDirectory($resolvedWorktree);
        BashTool::setSessionWorkingDirectory($context->sessionId, $resolvedWorktree);

        return ToolResult::success(
            "Created worktree: {$name}\n" .
            "Path: {$resolvedWorktree}\n" .
            "Branch: {$branchName}\n" .
            "The session's working directory has been switched to the worktree.\n" .
            "Use ExitWorktree to leave the worktree when done." .
            ($gitignoreWarning !== null ? "\n{$gitignoreWarning}" : '')
        );
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $cwd = $context->workingDirectory;
        $gitCheck = $this->git($cwd, ['rev-parse', '--is-inside-work-tree']);
        if (trim($gitCheck['stdout']) !== 'true') {
            return 'Not inside a git repository.';
        }
        return null;
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    private function resolveGitPath(string $cwd, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        try {
            $resolved = CanonicalPathResolver::resolve($path, $cwd);
        } catch (\Throwable) {
            return null;
        }

        return realpath($resolved) ?: $resolved;
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function readGitignore(string $path): ?string
    {
        if (! file_exists($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }

        $content = stream_get_contents($handle, self::MAX_GITIGNORE_BYTES + 1);
        fclose($handle);

        if (! is_string($content) || strlen($content) > self::MAX_GITIGNORE_BYTES) {
            return null;
        }

        return $content;
    }

    /** @param list<string> $args @return array{stdout: string, stderr: string, exitCode: int} */
    private function git(string $cwd, array $args): array
    {
        $result = (new HardenedGitRunner())->runGit($cwd, $args, 10.0);

        return [
            'stdout' => $result['stdout'],
            'stderr' => $result['timedOut'] ? 'Git command timed out.' : ($result['truncated'] ? 'Git command output exceeded limit.' : $result['stderr']),
            'exitCode' => $result['exitCode'],
        ];
    }
}

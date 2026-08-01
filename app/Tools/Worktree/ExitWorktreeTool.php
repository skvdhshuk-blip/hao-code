<?php

namespace HaoCode\Tools\Worktree;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class ExitWorktreeTool extends BaseTool
{
    public function name(): string
    {
        return 'ExitWorktree';
    }

    public function description(): string
    {
        return 'Exits a worktree session and returns to the original working directory.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['keep', 'remove'],
                    'description' => '"keep" leaves the worktree; "remove" deletes it.',
                ],
                'discard_changes' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to remove even with uncommitted changes.',
                ],
            ],
            'required' => ['action'],
        ], [
            'action' => 'required|string|in:keep,remove',
            'discard_changes' => 'nullable|boolean',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $action = $input['action'];
        $discardChanges = $input['discard_changes'] ?? false;
        $cwd = realpath($context->workingDirectory) ?: $context->workingDirectory;

        // Check if we're actually in a worktree
        $gitCommonDir = trim($this->git($cwd, ['rev-parse', '--git-common-dir'])['stdout']);
        $gitDir = trim($this->git($cwd, ['rev-parse', '--git-dir'])['stdout']);

        if ($gitCommonDir === '' || $gitCommonDir === $gitDir) {
            return ToolResult::error('Not in a worktree session. Nothing to exit.');
        }
        $commonDir = $this->resolveGitPath($cwd, $gitCommonDir);
        $mainRoot = $commonDir === null ? null : dirname($commonDir);
        if ($mainRoot === null || ! is_dir($mainRoot)) {
            return ToolResult::error('Could not resolve the main repository for this worktree.');
        }

        if ($action === 'remove') {
            // Check for uncommitted changes
            $status = $this->git($cwd, ['status', '--porcelain']);
            if (!empty(trim($status['stdout'])) && !$discardChanges) {
                $fileCount = count(array_filter(explode("\n", trim($status['stdout']))));
                return ToolResult::error(
                    "Worktree has {$fileCount} uncommitted file(s). Set discard_changes to true to remove anyway."
                );
            }

            // Get branch name before removing
            $branch = trim($this->git($cwd, ['branch', '--show-current'])['stdout']);

            // Remove worktree
            $removed = $this->git($mainRoot, ['worktree', 'remove', '--force', $cwd]);

            // Also delete the branch if it was a worktree-specific branch
            if ($branch && str_starts_with($branch, 'worktree-')) {
                $this->git($mainRoot, ['branch', '-D', $branch]);
            }

            if (is_dir($cwd)) {
                return ToolResult::error("Failed to remove worktree: {$removed['stderr']}{$removed['stdout']}");
            }
            $context->setWorkingDirectory($mainRoot);
            BashTool::setSessionWorkingDirectory($context->sessionId, $mainRoot);

            return ToolResult::success(
                "Worktree removed: {$cwd}\n" .
                "Returned to original directory."
            );
        }

        // action === 'keep'
        $branch = trim($this->git($cwd, ['branch', '--show-current'])['stdout']);
        $context->setWorkingDirectory($mainRoot);
        BashTool::setSessionWorkingDirectory($context->sessionId, $mainRoot);

        return ToolResult::success(
            "Worktree kept: {$cwd}\n" .
            "Branch: {$branch}\n" .
            "Returned to original directory. The worktree is still available."
        );
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

        // `git rev-parse` returns an absolute path on native Windows using
        // either drive-letter or UNC syntax. Treat those paths as absolute
        // instead of accidentally prefixing the current worktree directory.
        if (! $this->isAbsolutePath($path)) {
            $path = rtrim($cwd, '/').'/'.$path;
        }

        return realpath($path) ?: $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
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

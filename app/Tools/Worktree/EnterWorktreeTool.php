<?php

namespace HaoCode\Tools\Worktree;

use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class EnterWorktreeTool extends BaseTool
{
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
        ], [
            'name' => 'nullable|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $name = $input['name'] ?? null;
        $cwd = realpath($context->workingDirectory) ?: $context->workingDirectory;

        // Check if we're in a git repo
        $gitCheck = $this->git($cwd, ['rev-parse', '--is-inside-work-tree']);
        if (trim($gitCheck['stdout']) !== 'true') {
            return ToolResult::error('Not inside a git repository. Worktrees require a git repo.');
        }

        // Check if already in a linked worktree (git-dir differs from git-common-dir)
        $gitDir = trim($this->git($cwd, ['rev-parse', '--git-dir'])['stdout']);
        $commonDir = trim($this->git($cwd, ['rev-parse', '--git-common-dir'])['stdout']);
        if ($gitDir !== '' && $commonDir !== '' && $gitDir !== $commonDir) {
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

        $gitignore = $cwd . '/.gitignore';
        if (is_link($gitignore)) {
            return ToolResult::error('Refusing to update .gitignore because it is a symlink.');
        }

        // Create .claude/worktrees directory
        $claudeDir = $cwd . '/.claude';
        if (is_link($claudeDir)) {
            return ToolResult::error('Refusing to create worktree: .claude is a symlink.');
        }
        $worktreeBase = $claudeDir . '/worktrees';
        if (!is_dir($worktreeBase)) {
            if (! mkdir($worktreeBase, 0755, true)) {
                return ToolResult::error("Failed to create worktree directory: {$worktreeBase}");
            }
        }

        $worktreePath = $worktreeBase . '/' . $name;

        // Check if worktree already exists
        if (is_dir($worktreePath)) {
            return ToolResult::error("Worktree already exists: {$name}");
        }

        // Create worktree from HEAD
        $branchName = 'worktree-' . $name;
        $created = $this->git($cwd, [
            '-c', 'core.hooksPath=/dev/null',
            'worktree', 'add', '-b', $branchName, $worktreePath, 'HEAD',
        ]);

        if (!is_dir($worktreePath)) {
            return ToolResult::error("Failed to create worktree: {$created['stderr']}{$created['stdout']}");
        }

        // Add .claude/worktrees to .gitignore if not already
        $gitignoreContent = file_exists($gitignore) ? file_get_contents($gitignore) : '';
        if (!str_contains($gitignoreContent, '.claude/worktrees')) {
            file_put_contents($gitignore, "\n.claude/worktrees\n", FILE_APPEND);
        }
        $resolvedWorktree = realpath($worktreePath) ?: $worktreePath;
        $context->setWorkingDirectory($resolvedWorktree);
        BashTool::setSessionWorkingDirectory($context->sessionId, $resolvedWorktree);

        return ToolResult::success(
            "Created worktree: {$name}\n" .
            "Path: {$resolvedWorktree}\n" .
            "Branch: {$branchName}\n" .
            "The session's working directory has been switched to the worktree.\n" .
            "Use ExitWorktree to leave the worktree when done."
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

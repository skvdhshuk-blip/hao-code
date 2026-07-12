<?php

namespace HaoCode\Services\Git;

/**
 * Provides git context for the agent loop — current diff, branch info, etc.
 * Injected into the system prompt so the agent has awareness of uncommitted changes.
 */
class GitContext
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {}

    /**
     * Get the git diff summary for the working tree (unstaged + staged changes).
     * Returns a formatted string suitable for system prompt injection.
     */
    public function getDiffContext(): string
    {
        if (!$this->isGitRepo()) {
            return '';
        }

        $branch = $this->getCurrentBranch();
        $remote = $this->getRemoteUrl();
        $defaultBranch = $this->getDefaultBranch();
        $diff = $this->getWorkingTreeDiff();
        $status = $this->getWorkingTreeStatus();
        $recentCommits = $this->getRecentCommits();

        $context = "\n# Git Status\n- Branch: {$branch}"
            . ($remote ? "\n- Remote: {$remote}" : '')
            . ($defaultBranch ? "\n- Default branch: {$defaultBranch}" : '');

        if ($recentCommits !== '') {
            $context .= "\n\n# Recent Commits\n```\n{$recentCommits}\n```";
        }

        if ($status === '') {
            $context .= "\n- Working tree: clean";
        } else {
            $context .= "\n\n# Uncommitted Changes\n```\n{$status}";
            if ($diff !== '') {
                $context .= "\n\n{$diff}";
            }
            $context .= "\n```";
        }

        return $context;
    }

    /**
     * Check if the current directory is inside a git repository.
     */
    public function isGitRepo(): bool
    {
        exec($this->gitCommand('rev-parse --is-inside-work-tree'), $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get the current branch name.
     */
    public function getCurrentBranch(): string
    {
        exec($this->gitCommand('rev-parse --abbrev-ref HEAD'), $output);
        return trim($output[0] ?? 'unknown');
    }

    /**
     * Whether the working tree has tracked or untracked changes.
     */
    public function hasUncommittedChanges(): bool
    {
        exec($this->gitCommand('status --porcelain'), $output, $exitCode);

        return $exitCode === 0 && trim(implode("\n", $output)) !== '';
    }

    /**
     * Get the remote URL.
     */
    public function getRemoteUrl(): string
    {
        exec($this->gitCommand('config --get remote.origin.url'), $output);
        return trim($output[0] ?? '');
    }

    /**
     * Get the default branch (main or master).
     */
    public function getDefaultBranch(): string
    {
        // Try to detect from remote HEAD
        exec($this->gitCommand('symbolic-ref refs/remotes/origin/HEAD'), $output);
        if (!empty($output[0])) {
            return str_replace('refs/remotes/origin/', '', $output[0]);
        }
        return '';
    }

    /**
     * Get a summary of working tree changes (diff stats + first few hunks).
     */
    public function getWorkingTreeDiff(): string
    {
        // Get diff stat summary
        exec($this->gitCommand('diff --stat HEAD'), $statOutput, $exitCode);
        if ($exitCode !== 0 || empty($statOutput)) {
            return '';
        }

        $stat = implode("\n", $statOutput);

        // If diff has many files, just return the stat summary
        $fileCount = count(array_filter($statOutput, fn($l) => str_contains($l, '|')));
        if ($fileCount > 50) {
            return $stat . "\n(diff truncated — use Bash tool to see full diff)";
        }

        // Get actual diff hunks (limited)
        exec($this->gitCommand('diff HEAD --no-color').' | head -200', $diffOutput);
        $diff = implode("\n", $diffOutput);

        if (mb_strlen($diff) > 5000) {
            return $stat . "\n\n(diff too large to include, showing stat only)";
        }

        return $stat . "\n\n" . $diff;
    }

    /**
     * 获取包含未跟踪文件在内的工作区状态摘要。
     *
     * 输出采用 git status --short 格式，并限制行数和字符数，避免大型
     * 工作区状态无限进入模型上下文。
     */
    private function getWorkingTreeStatus(): string
    {
        exec($this->gitCommand('status --short'), $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return '';
        }

        // 最多向模型展示前 100 条状态记录。
        $status = implode("\n", array_slice($output, 0, 100));

        return mb_substr($status, 0, 5000);
    }

    /**
     * Get recent commit history (last 5 commits, one-line format).
     */
    public function getRecentCommits(int $count = 5): string
    {
        exec($this->gitCommand('log --oneline -n '.max(1, $count)), $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Check if a file is gitignored.
     */
    public function isGitIgnored(string $path): bool
    {
        exec($this->gitCommand('check-ignore -q '.escapeshellarg($path)), $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get the git root directory.
     */
    public function getGitRoot(): string
    {
        exec($this->gitCommand('rev-parse --show-toplevel'), $output);
        return trim($output[0] ?? ($this->workingDirectory ?? getcwd()));
    }

    /**
     * 为当前运行目录构造安全的 Git 命令。
     */
    private function gitCommand(string $arguments): string
    {
        $workingDirectory = $this->workingDirectory ?? getcwd();

        return 'git -C '.escapeshellarg($workingDirectory).' '.$arguments.' 2>/dev/null';
    }
}

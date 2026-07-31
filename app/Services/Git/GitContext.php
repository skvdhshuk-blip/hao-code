<?php

declare(strict_types=1);

namespace HaoCode\Services\Git;

/**
 * Provides git context for the agent loop — current diff, branch info, etc.
 * Volatile details are injected into the first user turn so the system prompt
 * remains a cache-stable session prefix.
 */
class GitContext
{
    private int $snapshotDepth = 0;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    public function __construct(
        private readonly ?string $workingDirectory = null,
        private readonly ?HardenedGitRunner $gitRunner = null,
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
        $workingTree = $this->getWorkingTreeState();
        $status = $workingTree['status'];
        $diff = $workingTree['has_tracked_changes'] ? $this->getWorkingTreeDiff() : '';
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
        return $this->snapshotValue('is_git_repo', function (): bool {
            $result = $this->git(['rev-parse', '--is-inside-work-tree']);

            return $result['exitCode'] === 0 && trim($result['stdout']) === 'true';
        });
    }

    /**
     * Get the current branch name.
     */
    public function getCurrentBranch(): string
    {
        return $this->snapshotValue('current_branch', function (): string {
            $result = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);
            $branch = trim($result['stdout']);

            return $result['exitCode'] === 0 && $branch !== '' ? $branch : 'unknown';
        });
    }

    /**
     * Whether the working tree has tracked or untracked changes.
     */
    public function hasUncommittedChanges(): bool
    {
        $result = $this->git(['status', '--porcelain']);

        return $result['exitCode'] === 0 && trim($result['stdout']) !== '';
    }

    /**
     * Get the remote URL.
     */
    public function getRemoteUrl(): string
    {
        return $this->snapshotValue('remote_url', function (): string {
            $result = $this->git(['config', '--get', 'remote.origin.url']);

            return $result['exitCode'] === 0 ? trim($result['stdout']) : '';
        });
    }

    /**
     * Get the default branch (main or master).
     */
    public function getDefaultBranch(): string
    {
        return $this->snapshotValue('default_branch', function (): string {
            // Try to detect from remote HEAD
            $result = $this->git(['symbolic-ref', 'refs/remotes/origin/HEAD']);
            $ref = trim($result['stdout']);
            if ($result['exitCode'] === 0 && $ref !== '') {
                return str_replace('refs/remotes/origin/', '', $ref);
            }

            return '';
        });
    }

    /**
     * Cache repository metadata only while one prompt is being assembled.
     */
    public function beginSnapshot(): void
    {
        if ($this->snapshotDepth === 0) {
            $this->snapshot = [];
        }
        $this->snapshotDepth++;
    }

    public function endSnapshot(): void
    {
        if ($this->snapshotDepth === 0) {
            return;
        }

        $this->snapshotDepth--;
        if ($this->snapshotDepth === 0) {
            $this->snapshot = [];
        }
    }

    /**
     * Get a summary of working tree changes (diff stats + first few hunks).
     */
    public function getWorkingTreeDiff(): string
    {
        // Get diff stat summary
        $statResult = $this->git([
            '--no-pager',
            'diff',
            '--no-ext-diff',
            '--no-textconv',
            '--no-renames',
            '--stat',
            'HEAD',
            '--',
        ]);
        $statOutput = $this->lines($statResult['stdout']);
        if ($statResult['exitCode'] !== 0 || $statOutput === []) {
            return '';
        }

        $stat = implode("\n", $statOutput);

        // If diff has many files, just return the stat summary
        $fileCount = count(array_filter($statOutput, fn($l) => str_contains($l, '|')));
        if ($fileCount > 50) {
            return $stat . "\n(diff truncated — use Bash tool to see full diff)";
        }

        // Get actual diff hunks (limited)
        $diffResult = $this->git([
            '--no-pager',
            'diff',
            '--no-color',
            '--no-ext-diff',
            '--no-textconv',
            '--no-renames',
            'HEAD',
            '--',
        ]);
        $diff = implode("\n", array_slice($this->lines($diffResult['stdout']), 0, 200));

        if ($diffResult['exitCode'] !== 0 || mb_strlen($diff) > 5000) {
            return $stat . "\n\n(diff too large to include, showing stat only)";
        }

        return $stat . "\n\n" . $diff;
    }

    /**
     * 获取包含未跟踪文件在内的工作区状态摘要。
     *
     * 输出采用 git status --short 格式，并限制行数和字符数，避免大型
     * 工作区状态无限进入模型上下文。
     *
     * @return array{status: string, has_tracked_changes: bool}
     */
    private function getWorkingTreeState(): array
    {
        $result = $this->git(['status', '--short']);
        $output = $this->lines($result['stdout']);
        if ($result['exitCode'] !== 0 || $output === []) {
            return ['status' => '', 'has_tracked_changes' => false];
        }

        $hasTrackedChanges = false;
        foreach ($output as $line) {
            if ($line !== '' && ! str_starts_with($line, '??')) {
                $hasTrackedChanges = true;
                break;
            }
        }

        // 最多向模型展示前 100 条状态记录。
        $status = implode("\n", array_slice($output, 0, 100));

        return [
            'status' => mb_substr($status, 0, 5000),
            'has_tracked_changes' => $hasTrackedChanges,
        ];
    }

    /**
     * Get recent commit history (last 5 commits, one-line format).
     */
    public function getRecentCommits(int $count = 5): string
    {
        $result = $this->git(['--no-pager', 'log', '--oneline', '-n', (string) max(1, $count)]);
        $output = $this->lines($result['stdout']);

        if ($result['exitCode'] !== 0 || empty($output)) {
            return '';
        }

        return implode("\n", $output);
    }

    /**
     * Check if a file is gitignored.
     */
    public function isGitIgnored(string $path): bool
    {
        $result = $this->git(['check-ignore', '-q', '--', $path]);

        return $result['exitCode'] === 0;
    }

    /**
     * Get the git root directory.
     */
    public function getGitRoot(): string
    {
        $result = $this->git(['--no-pager', 'rev-parse', '--show-toplevel']);
        $root = trim($result['stdout']);

        return $result['exitCode'] === 0 && $root !== '' ? $root : ($this->workingDirectory ?? getcwd());
    }

    /**
     * @param list<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, aborted: bool, truncated: bool}
     */
    private function git(array $args): array
    {
        $workingDirectory = $this->workingDirectory ?? getcwd();

        return ($this->gitRunner ?? new HardenedGitRunner())->runGit($workingDirectory, $args);
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        $output = trim($output, "\r\n");
        if ($output === '') {
            return [];
        }

        return preg_split('/\r\n|\r|\n/', $output) ?: [];
    }

    private function snapshotValue(string $key, callable $resolver): mixed
    {
        if ($this->snapshotDepth === 0) {
            return $resolver();
        }

        if (! array_key_exists($key, $this->snapshot)) {
            $this->snapshot[$key] = $resolver();
        }

        return $this->snapshot[$key];
    }
}

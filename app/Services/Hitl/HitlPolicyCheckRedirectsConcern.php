<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\Bash\ReadOnlyCommandSafety;

trait HitlPolicyCheckRedirectsConcern
{

    /**
     * Redirect sinks are writes: targets must stay inside the workspace, and
     * targets inside the system temp dir are downgraded from a red line to a
     * gray area (routine workflows legitimately write scratch files there).
     * Sensitive material stays a red line regardless (check order unchanged).
     */
    private static function checkRedirects(string $segment, string $root): ?array
    {
        $cleaned = preg_replace(
            '/(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?>&(?:\d+|-)\b/',
            ' ',
            $segment,
        ) ?? $segment;
        $cleaned = preg_replace('/\d?>>?\s*\/dev\/null/', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace(
            '/(?:\d+|\{[A-Za-z_][A-Za-z0-9_]*\})?>&\s*(?=\S)/',
            '>',
            $cleaned,
        ) ?? $cleaned;
        $cleaned = str_replace('&>', '>', $cleaned);
        $cleaned = str_replace('>|', '>', $cleaned);

        $targets = [];
        if (preg_match_all('/>>?\s*([^\s|&;<>]+)/', $cleaned, $matches) > 0) {
            $targets = $matches[1];
        }
        if (preg_match_all('/\btee\s+(?:-\w+\s+)*([^\s|&;<>]+)/', $cleaned, $teeMatches) > 0) {
            $targets = array_merge($targets, $teeMatches[1]);
        }
        foreach ($targets as $target) {
            if (SensitivePathGuard::requiresShellPathReview("cat {$target}")) {
                return self::verdict(self::ASK, 'Redirect target uses dynamic shell path expansion.');
            }
            $target = trim($target, "\"'");
            if ($target === '') {
                continue;
            }
            $resolved = self::resolvePath($target, $root);
            if ($resolved === null) {
                return self::verdict(self::RED_LINE, "Redirect target '{$target}' cannot be resolved safely.");
            }
            if (! self::isWithinWorkspace($resolved, $root)) {
                if (self::isWithinTempDir($resolved)) {
                    return self::verdict(self::GRAY, "Redirect writes to the system temp dir ('{$resolved}').");
                }
                return self::verdict(self::RED_LINE, "Redirect writes outside the workspace ('{$resolved}').");
            }
            $sensitive = SensitivePathGuard::matchSensitive($resolved);
            if ($sensitive !== null) {
                return self::verdict(self::RED_LINE, "Redirect targets sensitive material ({$sensitive}).");
            }
        }
        return null;
    }

    /**
     * Whether a resolved path lies inside the system temp dir: the realpath
     * of sys_get_temp_dir() plus the well-known temp roots, all realpath-
     * normalized (macOS symlinks /tmp to /private/tmp). Comparison is on path
     * boundaries so sibling directories like /tmpfoo never qualify.
     */
    private static function isWithinTempDir(string $resolved): bool
    {
        foreach (self::tempDirs() as $dir) {
            if (CanonicalPathResolver::isWithin($resolved, $dir)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function tempDirs(): array
    {
        static $dirs = null;
        if ($dirs === null) {
            $dirs = [];
            foreach ([sys_get_temp_dir(), '/tmp', '/var/tmp', '/private/tmp', '/private/var/tmp'] as $candidate) {
                $real = realpath($candidate);
                if (is_string($real) && $real !== '') {
                    $dirs[$real] = true;
                }
            }
            $dirs = array_keys($dirs);
        }
        return $dirs;
    }

    /** @return array{level: string, reason: string} */
    private static function classifyRm(array $args, string $root): array
    {
        $destructive = false;
        $targets = [];
        foreach ($args as $arg) {
            if ($arg === '--') {
                continue;
            }
            if (str_starts_with($arg, '--')) {
                if (in_array($arg, ['--recursive', '--force', '--no-preserve-root'], true)) {
                    $destructive = true;
                }
                continue;
            }
            if (str_starts_with($arg, '-') && strlen($arg) > 1) {
                if (strpbrk(substr($arg, 1), 'rRf') !== false) {
                    $destructive = true;
                }
                continue;
            }
            $targets[] = $arg;
        }

        $home = getenv('HOME');
        $home = is_string($home) && $home !== '' ? realpath($home) : false;
        foreach ($targets as $target) {
            $resolved = self::resolvePath($target, $root);
            if ($resolved === null) {
                continue; // unresolvable targets stay gray below.
            }
            $broad = $resolved === '/'
                || $resolved === $root
                || ($home !== false && $resolved === $home)
                || ! self::isWithinWorkspace($resolved, $root);
            if ($destructive && $broad) {
                return self::verdict(self::RED_LINE, "rm with recursive/force flags targets '{$resolved}', a broad or out-of-workspace scope.");
            }
        }
        return self::verdict(self::GRAY, 'rm deletes files; left for guardian review.');
    }

    /** git subcommands that publish, rewrite history, or discard work. */
    private static function classifyGitRedLine(array $args): ?array
    {
        $parsed = self::gitArgs($args);
        if ($parsed === null) {
            return null;
        }
        [$subcommand, $rest] = $parsed;
        switch ($subcommand) {
            case 'push':
                return self::verdict(self::RED_LINE, 'git push publishes history to a remote.');
            case 'rebase':
                return self::verdict(self::RED_LINE, 'git rebase rewrites history.');
            case 'remote':
                return self::verdict(self::RED_LINE, 'git remote modifies remote configuration.');
            case 'filter-branch':
                return self::verdict(self::RED_LINE, 'git filter-branch rewrites history broadly.');
            case 'update-ref':
                if (in_array('-d', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git update-ref -d deletes a ref.');
                }
                return null;
            case 'reset':
                if (in_array('--hard', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git reset --hard discards uncommitted changes.');
                }
                return null;
            case 'clean':
                foreach ($rest as $arg) {
                    if (str_starts_with($arg, '-') && strpbrk($arg, 'fd') !== false) {
                        return self::verdict(self::RED_LINE, 'git clean permanently deletes untracked files.');
                    }
                }
                return null;
            case 'checkout':
                if (in_array('.', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git checkout . discards working tree changes.');
                }
                if (in_array('--', $rest, true)) {
                    return self::verdict(self::RED_LINE, 'git checkout -- discards working tree changes.');
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Strip benign global git flags and split off the subcommand.
     *
     * @return array{0: string, 1: string[]}|null [subcommand, remaining args]; null when scope-changing flags appear.
     */
    private static function gitArgs(array $args): ?array
    {
        while ($args !== [] && str_starts_with($args[0], '-')) {
            if ($args[0] !== '--no-pager') {
                return null; // -C / --git-dir / --work-tree change scope; fail to gray.
            }
            array_shift($args);
        }
        if ($args === []) {
            return null;
        }
        $subcommand = array_shift($args);
        return [$subcommand, $args];
    }

    /** @return array{level: string, reason: string}|null auto_allow/gray verdict, null when not allowlisted */
    private static function matchAllowlist(string $command, array $args, string $root): ?array
    {
        if (in_array($command, self::SIMPLE_ALLOWLIST, true)) {
            return self::verdict(self::AUTO_ALLOW, "Read-only command '{$command}'.");
        }
        if ($command === 'find') {
            foreach (self::FIND_MUTATING_ACTIONS as $flag) {
                if (in_array($flag, $args, true)) {
                    return null;
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Read-only find without -exec/-delete.');
        }
        if ($command === 'sed') {
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--in-place') || preg_match('/^-[a-zA-Z]*i/', $arg) === 1) {
                    return self::verdict(self::GRAY, 'sed -i edits files in place.');
                }
            }
            return self::verdict(self::AUTO_ALLOW, 'Read-only sed stream edit.');
        }
        if ($command === 'tar') {
            $letters = ltrim($args[0] ?? '', '-');
            if ($letters !== '' && preg_match('/^[a-z]+$/', $letters) === 1
                && str_contains($letters, 't') && strpbrk($letters, 'xc') === false) {
                return self::verdict(self::AUTO_ALLOW, 'Read-only tar listing.');
            }
            return null; // tar create/extract writes files; stays gray.
        }
        if ($command === 'unzip') {
            return ($args[0] ?? '') === '-l'
                ? self::verdict(self::AUTO_ALLOW, 'Read-only unzip listing.')
                : null;
        }
        if ($command === 'git') {
            $parsed = self::gitArgs($args);
            if ($parsed === null) {
                return null;
            }
            [$subcommand, $rest] = $parsed;
            if (in_array($subcommand, ['status', 'log', 'diff', 'show', 'rev-parse', 'ls-files', 'blame'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Read-only git {$subcommand}.");
            }
            // Local, reversible git operations are routine development work.
            if (in_array($subcommand, ['add', 'commit', 'switch', 'stash'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Local reversible git {$subcommand}.");
            }
            if ($subcommand === 'restore') {
                return in_array('--staged', $rest, true)
                    ? self::verdict(self::AUTO_ALLOW, 'git restore --staged only unstages files.')
                    : null; // plain git restore discards working tree changes; stays gray.
            }
            if ($subcommand === 'tag') {
                foreach ($rest as $arg) {
                    if ($arg === '--delete' || preg_match('/^-[a-zA-Z]*d/', $arg) === 1) {
                        return null; // tag deletion stays gray.
                    }
                }
                return self::verdict(self::AUTO_ALLOW, 'Local git tag operation.');
            }
            if ($subcommand === 'branch') {
                return self::verdict(self::AUTO_ALLOW, 'Local git branch operation (recoverable via reflog).');
            }
            return null;
        }
        if ($command === 'php') {
            return in_array($args[0] ?? '', ['-v', '--version', '-l'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only php invocation.')
                : null;
        }
        if ($command === 'node') {
            return in_array($args[0] ?? '', ['-v', '--version'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only node invocation.')
                : null;
        }
        // Package managers: workspace-local dependency/build/test workflows.
        if (in_array($command, ['npm', 'yarn', 'pnpm', 'bun'], true)) {
            $sub = $args[0] ?? '';
            if (in_array($sub, ['-v', '--version'], true)) {
                return self::verdict(self::AUTO_ALLOW, "Read-only {$command} invocation.");
            }
            if (in_array($sub, [
                'install', 'add', 'remove', 'uninstall', 'update', 'test', 'run',
                'ci', 'exec', 'dlx', 'ls', 'list', 'outdated', 'audit',
            ], true)) {
                return self::verdict(self::AUTO_ALLOW, "Workspace-local {$command} {$sub}.");
            }
            return null;
        }
        if (in_array($command, ['npx', 'bunx'], true)) {
            return self::verdict(self::AUTO_ALLOW, "Package runner {$command}.");
        }
        if ($command === 'composer') {
            return in_array($args[0] ?? '', ['show', 'validate'], true)
                ? self::verdict(self::AUTO_ALLOW, 'Read-only composer invocation.')
                : null;
        }
        if ($command === 'tsc') {
            $onlyFlags = true;
            foreach ($args as $arg) {
                if (! str_starts_with($arg, '-')) {
                    $onlyFlags = false;
                    break;
                }
            }
            return ($onlyFlags && in_array('--noEmit', $args, true))
                ? self::verdict(self::AUTO_ALLOW, 'Read-only tsc --noEmit.')
                : null;
        }
        if (in_array($command, ['mkdir', 'touch', 'cp', 'mv'], true)) {
            $paths = [];
            foreach ($args as $arg) {
                if ($arg === '--' || str_starts_with($arg, '-')) {
                    continue;
                }
                $paths[] = $arg;
            }
            if ($paths === []) {
                return null;
            }
            foreach ($paths as $path) {
                $resolved = self::resolvePath($path, $root);
                if ($resolved === null || ! self::isWithinWorkspace($resolved, $root)) {
                    return self::verdict(self::GRAY, "{$command} touches a path outside the workspace.");
                }
            }
            return self::verdict(self::AUTO_ALLOW, "{$command} stays inside the workspace.");
        }
        return null;
    }

    /**
     * Resolve a path to an absolute, symlink-collapsed form.
     *
     * Handles ~ expansion, relative paths against the workspace root, dot
     * segments, and symlink prefixes (via the nearest existing ancestor).
     * Returns null when resolution is impossible — callers fail closed.
     */
    private static function resolvePath(string $rawPath, string $root): ?string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return null;
        }
        if (str_starts_with($rawPath, '~')
            && $rawPath !== '~'
            && ! str_starts_with($rawPath, '~/')
            && ! str_starts_with($rawPath, '~\\')
        ) {
            return null; // ~user expansion is unsupported; fail closed.
        }

        try {
            $resolved = CanonicalPathResolver::resolve($rawPath, $root);
        } catch (\Throwable) {
            return null;
        }

        if (($rawPath === '~' || str_starts_with($rawPath, '~/') || str_starts_with($rawPath, '~\\'))
            && preg_match('/(^|[\/\\\\])~([\/\\\\]|$)/', $resolved) === 1
        ) {
            return null;
        }

        return trim($resolved) === '' ? null : $resolved;
    }

    private static function isWithinWorkspace(string $resolved, string $root): bool
    {
        return CanonicalPathResolver::isWithin($resolved, $root);
    }
}

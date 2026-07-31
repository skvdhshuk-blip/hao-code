<?php

declare(strict_types=1);

namespace HaoCode\Tools\Bash;

use HaoCode\Services\Permissions\SensitivePathGuard;

/**
 * Parameter-level safety checks for commands that are otherwise read-only.
 *
 * @internal
 */
final class ReadOnlyCommandSafety
{
    /** @return string|null Why the segment must not use the read-only fast path. */
    public static function mutationReason(string $segment): ?string
    {
        $tokens = preg_split(
            '/\s+/',
            trim(SensitivePathGuard::normalizeShellStaticText($segment)),
        ) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return null;
        }

        $command = basename(array_shift($tokens));

        return match ($command) {
            'find' => self::findReason($tokens),
            'tee' => self::teeReason($tokens),
            'sort' => self::sortReason($tokens),
            'uniq' => self::uniqReason($tokens),
            'file' => self::fileReason($tokens),
            'git' => self::gitReason($tokens),
            'date' => self::dateReason($tokens),
            'hostname' => self::hostnameReason($tokens),
            default => null,
        };
    }

    /** @param string[] $args */
    private static function findReason(array $args): ?string
    {
        foreach ([
            '-delete', '-exec', '-execdir', '-ok', '-okdir',
            '-fprint', '-fprint0', '-fprintf', '-fls',
        ] as $action) {
            if (in_array($action, $args, true)) {
                return "find {$action} may mutate files or execute commands.";
            }
        }

        return null;
    }

    /** @param string[] $args */
    private static function teeReason(array $args): ?string
    {
        $afterOptions = false;
        foreach ($args as $arg) {
            if ($arg === '--') {
                $afterOptions = true;
                continue;
            }
            if (! $afterOptions && str_starts_with($arg, '-')) {
                continue;
            }

            return 'tee writes to one or more files.';
        }

        return null;
    }

    /** @param string[] $args */
    private static function sortReason(array $args): ?string
    {
        foreach ($args as $arg) {
            if ($arg === '-o'
                || preg_match('/^-[^-]*o/', $arg) === 1
                || $arg === '--output'
                || str_starts_with($arg, '--output=')
            ) {
                return 'sort output option writes to a file.';
            }
            if ($arg === '--compress-program' || str_starts_with($arg, '--compress-program=')) {
                return 'sort compress-program executes an external command.';
            }
        }

        return null;
    }

    /** @param string[] $args */
    private static function uniqReason(array $args): ?string
    {
        $positionals = [];
        $consumeValue = false;
        $afterOptions = false;
        foreach ($args as $arg) {
            if ($consumeValue) {
                $consumeValue = false;
                continue;
            }
            if (! $afterOptions && $arg === '--') {
                $afterOptions = true;
                continue;
            }
            if (! $afterOptions && in_array(
                $arg,
                ['-f', '--skip-fields', '-s', '--skip-chars', '-w', '--check-chars'],
                true,
            )) {
                $consumeValue = true;
                continue;
            }
            if (! $afterOptions && str_starts_with($arg, '-')
                && $arg !== '-'
            ) {
                continue;
            }
            $positionals[] = $arg;
        }

        return count($positionals) > 1 && $positionals[1] !== '-'
            ? 'uniq output operand writes to a file.'
            : null;
    }

    /** @param string[] $args */
    private static function fileReason(array $args): ?string
    {
        foreach ($args as $arg) {
            if ($arg === '--compile'
                || str_starts_with($arg, '--compile=')
                || preg_match('/^-[^-]*C/', $arg) === 1
            ) {
                return 'file --compile writes a compiled magic database.';
            }
        }

        return null;
    }

    /** @param string[] $args */
    private static function gitReason(array $args): ?string
    {
        if ($args === []) {
            return null;
        }

        $subcommand = array_shift($args);
        foreach ($args as $arg) {
            if ($arg === '--output'
                || str_starts_with($arg, '--output=')
                || $arg === '--ext-diff'
                || $arg === '--textconv'
            ) {
                return "git {$subcommand} option writes output or executes a helper.";
            }
        }

        return match ($subcommand) {
            'remote' => 'git remote accesses or changes remote configuration.',
            'branch' => self::gitBranchReason($args),
            'tag' => self::gitTagReason($args),
            default => null,
        };
    }

    /** @param string[] $args */
    private static function gitBranchReason(array $args): ?string
    {
        if ($args === []) {
            return null;
        }

        $safeExact = [
            '-a', '--all', '-r', '--remotes', '-v', '-vv', '--verbose',
            '--show-current', '--list', '-l', '--ignore-case', '--omit-empty',
            '--color', '--no-color', '--column', '--no-column',
        ];
        $valueOptions = [
            '--contains', '--no-contains', '--merged', '--no-merged',
            '--points-at', '--sort', '--format', '--abbrev',
        ];
        $listMode = false;
        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];
            if ($arg === '--list' || $arg === '-l') {
                $listMode = true;
            }
            if (in_array($arg, $safeExact, true)
                || preg_match('/^--(?:color|column|sort|format|abbrev)=/', $arg) === 1
            ) {
                continue;
            }
            if (in_array($arg, $valueOptions, true)) {
                $index++;
                continue;
            }
            if ($listMode && ! str_starts_with($arg, '-')) {
                continue;
            }

            return 'git branch arguments may create, move, or delete a branch.';
        }

        return null;
    }

    /** @param string[] $args */
    private static function gitTagReason(array $args): ?string
    {
        if ($args === []) {
            return null;
        }

        $safeExact = [
            '-l', '--list', '--ignore-case', '--omit-empty',
            '--color', '--no-color', '--column', '--no-column',
        ];
        $valueOptions = [
            '--contains', '--no-contains', '--merged', '--no-merged',
            '--points-at', '--sort', '--format',
        ];
        $listMode = false;
        for ($index = 0, $count = count($args); $index < $count; $index++) {
            $arg = $args[$index];
            if ($arg === '--list' || $arg === '-l') {
                $listMode = true;
            }
            if (in_array($arg, $safeExact, true)
                || preg_match('/^(?:-n\d*|--(?:color|column|sort|format)=)/', $arg) === 1
            ) {
                continue;
            }
            if (in_array($arg, $valueOptions, true)) {
                $index++;
                continue;
            }
            if ($listMode && ! str_starts_with($arg, '-')) {
                continue;
            }

            return 'git tag arguments may create, replace, sign, or delete a tag.';
        }

        return null;
    }

    /** @param string[] $args */
    private static function dateReason(array $args): ?string
    {
        $consumeValue = false;
        foreach ($args as $arg) {
            if ($consumeValue) {
                $consumeValue = false;
                continue;
            }
            if ($arg === '-s'
                || $arg === '--set'
                || str_starts_with($arg, '--set=')
                || $arg === '-a'
                || $arg === '--adjust'
            ) {
                return 'date arguments may change the system clock.';
            }
            if (in_array($arg, ['-d', '--date', '-r', '--reference', '-f', '--file'], true)) {
                $consumeValue = true;
                continue;
            }
            if (! str_starts_with($arg, '-') && ! str_starts_with($arg, '+')) {
                return 'date positional value may change the system clock.';
            }
        }

        return null;
    }

    /** @param string[] $args */
    private static function hostnameReason(array $args): ?string
    {
        $queryFlags = [
            '-a', '--alias', '-d', '--domain', '-f', '--fqdn', '--long',
            '-s', '--short', '-i', '--ip-address', '-I', '--all-ip-addresses',
            '-y', '--yp', '--nis',
        ];
        foreach ($args as $arg) {
            if (! in_array($arg, $queryFlags, true)) {
                return 'hostname arguments may change the system hostname.';
            }
        }

        return null;
    }
}

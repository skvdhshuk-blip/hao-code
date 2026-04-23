<?php

namespace HaoCode\Skill\Registry;

/**
 * Interface for running git shell commands — isolated for testability.
 */
interface GitRunner
{
    /**
     * Run a git command and return [exitCode, stdout, stderr].
     *
     * @param  string[]  $args  git arguments (e.g. ['clone', '--depth', '1', $url, $dir])
     * @param  string|null  $cwd  working directory, or null for current
     * @return array{int, string, string}
     */
    public function run(array $args, ?string $cwd = null): array;
}

<?php

namespace HaoCode\Skill\Registry;

use HaoCode\Services\Git\HardenedGitRunner;

/**
 * Production GitRunner backed by the shared hardened Git runner.
 */
class ProcOpenGitRunner implements GitRunner
{
    public function run(array $args, ?string $cwd = null): array
    {
        $result = (new HardenedGitRunner())->runGit($cwd ?? getcwd(), $args, 30.0);

        $stderr = $result['stderr'];
        if ($result['timedOut']) {
            $stderr = 'Git command timed out.';
        } elseif ($result['aborted']) {
            $stderr = 'Git command aborted.';
        } elseif ($result['truncated']) {
            $stderr = 'Git command output exceeded limit.';
        }

        return [$result['exitCode'], $result['stdout'], $stderr];
    }
}

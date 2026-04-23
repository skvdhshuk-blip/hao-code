<?php

namespace HaoCode\Skill\Registry;

/**
 * Production GitRunner using proc_open — zero composer dependencies.
 */
class ProcOpenGitRunner implements GitRunner
{
    public function run(array $args, ?string $cwd = null): array
    {
        $cmd = array_merge(['git'], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);

        if ($process === false) {
            return [1, '', 'proc_open failed'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, (string) $stdout, (string) $stderr];
    }
}

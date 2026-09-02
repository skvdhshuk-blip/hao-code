<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Tools\Bash\BashTool;

/**
 * Turn-boundary producer that tells the model about background work that finished.
 *
 * Without this the model has to poll TaskGet to discover that an agent or a
 * backgrounded command is done, which it rarely does on its own. Results are
 * claimed as they are announced, so each one is delivered exactly once whether
 * the model learns of it here or by polling.
 *
 * @internal
 */
final class BackgroundCompletionNotifier
{
    private const RESULT_CHAR_LIMIT = 8000;

    /** @param \Closure(): ?BackgroundAgentManager $agents Resolved lazily; a text-only run never touches storage. */
    public function __construct(
        private readonly \Closure $agents,
        private readonly bool $watchBash = true,
    ) {}

    public function __invoke(int $completedTurn, string $sessionId): ?string
    {
        $items = [...$this->agentNotices($sessionId), ...$this->bashNotices($sessionId)];
        if ($items === []) {
            return null;
        }

        return "# Background task updates\n\n".implode("\n", $items)."\n\n"
            .'These results are delivered once. Use TaskGet or TaskList for the current '
            .'status of other tasks.';
    }

    /** @return list<string> */
    private function agentNotices(string $sessionId): array
    {
        try {
            $manager = ($this->agents)();
            if ($manager === null) {
                return [];
            }
            $claimed = $manager->claimCompletionNotices($sessionId);
        } catch (\Throwable) {
            // A completion notice is a convenience. A failure to read background
            // state must never take down the run that is merely passing by.
            return [];
        }

        $notices = [];
        foreach ($claimed as $state) {
            $id = (string) ($state['id'] ?? '');
            $label = $this->describeAgent($state);
            $status = (string) ($state['status'] ?? 'completed');

            if ($status === 'completed') {
                $result = $this->trim((string) ($state['last_result'] ?? ''));
                $notices[] = $result === ''
                    ? "- Background agent {$id}{$label} completed with no output."
                    : "- Background agent {$id}{$label} completed:\n".$this->indent($result);

                continue;
            }

            $error = $this->trim((string) ($state['error'] ?? ''));
            $reason = $status === 'dead' ? 'stopped unexpectedly' : 'failed';
            $notices[] = $error === ''
                ? "- Background agent {$id}{$label} {$reason}."
                : "- Background agent {$id}{$label} {$reason}: {$error}";
        }

        return $notices;
    }

    /** @return list<string> */
    private function bashNotices(string $sessionId): array
    {
        if (! $this->watchBash) {
            return [];
        }

        try {
            $harvested = BashTool::harvestCompleted($sessionId);
        } catch (\Throwable) {
            return [];
        }

        $notices = [];
        foreach ($harvested as $task) {
            $id = $task['taskId'];
            $command = $task['command'];

            if ($task['result'] === null) {
                $bytes = $task['outputBytes'];
                $notices[] = "- Background Bash {$id} (`{$command}`) finished with {$bytes} bytes of "
                    ."output; call BashOutput with task_id \"{$id}\" to read it.";

                continue;
            }

            $result = $task['result'];
            $exitCode = ($result->metadata ?? [])['exitCode'] ?? null;
            $outcome = $result->isError
                ? 'failed'.(is_int($exitCode) ? " with exit code {$exitCode}" : '')
                : 'exited with code '.(is_int($exitCode) ? (string) $exitCode : '0');
            $output = $this->trim($result->output);
            $notices[] = $output === ''
                ? "- Background Bash {$id} (`{$command}`) {$outcome}, no output."
                : "- Background Bash {$id} (`{$command}`) {$outcome}:\n".$this->indent($output);
        }

        return $notices;
    }

    /** @param array<string, mixed> $state */
    private function describeAgent(array $state): string
    {
        $description = trim((string) ($state['description'] ?? ''));

        return $description === '' ? '' : " (\"{$description}\")";
    }

    private function trim(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= self::RESULT_CHAR_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::RESULT_CHAR_LIMIT)."\n… (truncated)";
    }

    private function indent(string $text): string
    {
        return '  '.str_replace("\n", "\n  ", $text);
    }
}

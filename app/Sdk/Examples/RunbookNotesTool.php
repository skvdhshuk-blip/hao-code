<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\SdkTool;

final class RunbookNotesTool extends SdkTool
{
    /** @var list<array{title: string, note: string}> */
    private array $notes = [];

    public function name(): string
    {
        return 'RunbookNotes';
    }

    public function description(): string
    {
        return 'Append incident notes to a shared runbook or list the current notes.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Whether to append a note or list the notes',
                'enum' => ['append', 'list'],
                'required' => true,
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Short note title when appending',
            ],
            'note' => [
                'type' => 'string',
                'description' => 'Detailed note body when appending',
            ],
        ];
    }

    public function handle(array $input): string
    {
        $action = $input['action'] ?? 'list';

        if ($action === 'list') {
            if ($this->notes === []) {
                return 'Runbook is empty.';
            }

            $lines = ['Runbook notes:'];
            foreach ($this->notes as $index => $note) {
                $lines[] = sprintf('%d. %s: %s', $index + 1, $note['title'], $note['note']);
            }

            return implode("\n", $lines);
        }

        $title = trim((string) ($input['title'] ?? 'Untitled note'));
        $note = trim((string) ($input['note'] ?? ''));
        $this->notes[] = [
            'title' => $title,
            'note' => $note,
        ];

        return sprintf('Stored note #%d: %s - %s', count($this->notes), $title, $note);
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }
}

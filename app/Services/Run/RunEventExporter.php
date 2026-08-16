<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

use HaoCode\Services\Security\SensitiveDataRedactor;

/** Read-only, safe-by-default redacted JSONL export. @internal */
final class RunEventExporter
{
    private readonly SensitiveDataRedactor $redactor;

    public function __construct(
        private readonly RunEventStoreInterface $events,
        ?SensitiveDataRedactor $redactor = null,
    ) {
        $this->redactor = $redactor ?? new SensitiveDataRedactor;
    }

    public function export(string $runId): string
    {
        $lines = [];
        foreach ($this->events->read($runId) as $event) {
            $encoded = json_encode(
                $this->redactor->redactRunEvent($event->toArray()),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if ($encoded === false) {
                throw new \RuntimeException('Could not serialize RunEvent export.');
            }
            $lines[] = $encoded;
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}

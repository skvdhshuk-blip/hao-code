<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** Read-only JSONL export. @internal */
final class RunEventExporter
{
    public function __construct(private readonly RunEventStoreInterface $events) {}

    public function export(string $runId): string
    {
        $lines = [];
        foreach ($this->events->read($runId) as $event) {
            $encoded = json_encode($event->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('Could not serialize RunEvent export.');
            }
            $lines[] = $encoded;
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}

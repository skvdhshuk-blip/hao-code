<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;

/**
 * Incremental Server-Sent Events decoder used by Streamable HTTP transports.
 *
 * @phpstan-type SseEvent array{data: string, id: ?string, retry: ?int, event: ?string}
 */
final class McpSseDecoder
{
    private BoundedSseLineBuffer $lineReader;

    /** @var list<string> */
    private array $dataLines = [];

    private int $dataBytes = 0;

    private ?string $lastEventId = null;

    private ?string $pendingEventId = null;

    private ?int $retry = null;

    private ?string $eventType = null;

    private bool $hasFields = false;

    public function __construct(
        private readonly int $maxBufferBytes,
    ) {
        $this->lineReader = new BoundedSseLineBuffer($maxBufferBytes);
    }

    /**
     * @return list<array{data: string, id: ?string, retry: ?int, event: ?string}>
     */
    public function push(string $chunk, bool $endOfStream = false): array
    {
        try {
            $lines = $this->lineReader->push($chunk, $endOfStream);
        } catch (\LengthException) {
            $this->resetAfterOverflow();
        }
        $events = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $event = $this->dispatchEvent();
                if ($event !== null) {
                    $events[] = $event;
                }

                continue;
            }

            $this->consumeLine($line);
            $this->guardBufferSize();
        }

        if ($endOfStream) {
            $event = $this->dispatchEvent();
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $this->guardBufferSize();

        return $events;
    }

    private function consumeLine(string $line): void
    {
        if (str_starts_with($line, ':')) {
            return;
        }

        $this->hasFields = true;

        $separator = strpos($line, ':');
        if ($separator === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
        }

        match ($field) {
            'data' => $this->dataLines[] = $value,
            'event' => $this->eventType = $value,
            'id' => $this->pendingEventId = str_contains($value, "\0") ? $this->pendingEventId : $value,
            'retry' => ctype_digit($value) ? $this->retry = (int) $value : null,
            default => null,
        };

        if ($field === 'data') {
            $this->dataBytes += strlen($value);
        }
    }

    /**
     * @return array{data: string, id: ?string, retry: ?int, event: ?string}|null
     */
    private function dispatchEvent(): ?array
    {
        if (! $this->hasFields) {
            return null;
        }

        if ($this->pendingEventId !== null) {
            $this->lastEventId = $this->pendingEventId;
        }

        $event = [
            'data' => implode("\n", $this->dataLines),
            'id' => $this->lastEventId,
            'retry' => $this->retry,
            'event' => $this->eventType,
        ];

        $this->dataLines = [];
        $this->dataBytes = 0;
        $this->pendingEventId = null;
        $this->retry = null;
        $this->eventType = null;
        $this->hasFields = false;

        return $event;
    }

    private function guardBufferSize(): void
    {
        if ($this->bufferedBytes() <= $this->maxBufferBytes) {
            return;
        }

        $this->resetAfterOverflow();
    }

    private function bufferedBytes(): int
    {
        return $this->lineReader->bufferedBytes()
            + $this->dataBytes
            + strlen($this->pendingEventId ?? '')
            + strlen($this->eventType ?? '');
    }

    private function resetAfterOverflow(): never
    {
        $this->lineReader = new BoundedSseLineBuffer($this->maxBufferBytes);
        $this->dataLines = [];
        $this->dataBytes = 0;
        $this->lastEventId = null;
        $this->pendingEventId = null;
        $this->retry = null;
        $this->eventType = null;
        $this->hasFields = false;

        throw McpConnectionException::transport(
            "MCP SSE event exceeded {$this->maxBufferBytes} bytes",
        );
    }
}

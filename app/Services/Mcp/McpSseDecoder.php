<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

/**
 * Incremental Server-Sent Events decoder used by Streamable HTTP transports.
 *
 * @phpstan-type SseEvent array{data: string, id: ?string, retry: ?int, event: ?string}
 */
final class McpSseDecoder
{
    private string $lineBuffer = '';

    /** @var list<string> */
    private array $dataLines = [];

    private ?string $lastEventId = null;

    private ?string $pendingEventId = null;

    private ?int $retry = null;

    private ?string $eventType = null;

    private bool $hasFields = false;

    public function __construct(
        private readonly int $maxBufferBytes,
    ) {}

    /**
     * @return list<array{data: string, id: ?string, retry: ?int, event: ?string}>
     */
    public function push(string $chunk, bool $endOfStream = false): array
    {
        // A gateway can keep an event unterminated indefinitely. Reject a
        // delimiter-free chunk before concatenating it so the guard itself does
        // not require allocating an unbounded line buffer first.
        if ($chunk !== ''
            && strpbrk($chunk, "\r\n") === false
            && $this->bufferedBytes() + strlen($chunk) > $this->maxBufferBytes
        ) {
            $this->resetAfterOverflow();
        }

        $this->lineBuffer .= $chunk;
        $events = [];

        while (($line = $this->extractLine($endOfStream)) !== null) {
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

    private function extractLine(bool $endOfStream): ?string
    {
        $length = strlen($this->lineBuffer);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $this->lineBuffer[$offset];
            if ($character !== "\n" && $character !== "\r") {
                continue;
            }

            if ($character === "\r" && $offset + 1 === $length && ! $endOfStream) {
                return null;
            }

            $delimiterLength = $character === "\r"
                && isset($this->lineBuffer[$offset + 1])
                && $this->lineBuffer[$offset + 1] === "\n"
                ? 2
                : 1;
            $line = substr($this->lineBuffer, 0, $offset);
            $this->lineBuffer = substr($this->lineBuffer, $offset + $delimiterLength);

            return $line;
        }

        if ($endOfStream && $this->lineBuffer !== '') {
            $line = $this->lineBuffer;
            $this->lineBuffer = '';

            return $line;
        }

        return null;
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
        $dataBytes = array_sum(array_map('strlen', $this->dataLines));

        return strlen($this->lineBuffer)
            + $dataBytes
            + strlen($this->pendingEventId ?? '')
            + strlen($this->eventType ?? '');
    }

    private function resetAfterOverflow(): never
    {
        $this->lineBuffer = '';
        $this->dataLines = [];
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

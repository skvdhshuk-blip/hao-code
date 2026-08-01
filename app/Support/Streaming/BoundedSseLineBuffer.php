<?php

declare(strict_types=1);

namespace HaoCode\Support\Streaming;

/**
 * Incrementally splits SSE input without allowing an unterminated line to
 * grow beyond the configured byte ceiling.
 *
 * The caller still owns the incoming chunk, but this class never appends a
 * segment to its retained buffer until the segment's size has been checked.
 * It also handles CRLF delimiters split across two chunks.
 *
 * @internal
 */
final class BoundedSseLineBuffer
{
    private string $buffer = '';

    private bool $pendingCarriageReturn = false;

    public function __construct(private readonly int $maxLineBytes)
    {
        if ($maxLineBytes < 1) {
            throw new \InvalidArgumentException('SSE line limit must be positive.');
        }
    }

    /**
     * @return list<string>
     *
     * @throws \LengthException when a line exceeds the configured limit
     */
    public function push(string $chunk, bool $endOfStream = false): array
    {
        $lines = [];
        $length = strlen($chunk);
        $offset = 0;

        if ($this->pendingCarriageReturn) {
            if ($length === 0 && ! $endOfStream) {
                return [];
            }

            $this->pendingCarriageReturn = false;
            if ($length > 0 && $chunk[0] === "\n") {
                $offset = 1;
            }
            $lines[] = $this->takeLine();
        }

        while ($offset < $length) {
            $delimiterOffset = $this->nextDelimiterOffset($chunk, $offset);
            if ($delimiterOffset === null) {
                $this->appendChecked($chunk, $offset, $length - $offset);
                break;
            }

            $this->appendChecked($chunk, $offset, $delimiterOffset - $offset);
            $delimiter = $chunk[$delimiterOffset];
            $offset = $delimiterOffset + 1;

            if ($delimiter === "\r") {
                if ($offset >= $length) {
                    if (! $endOfStream) {
                        $this->pendingCarriageReturn = true;

                        break;
                    }
                } elseif ($chunk[$offset] === "\n") {
                    $offset++;
                }
            }

            $lines[] = $this->takeLine();
        }

        if ($endOfStream) {
            if ($this->pendingCarriageReturn) {
                $this->pendingCarriageReturn = false;
                $lines[] = $this->takeLine();
            } elseif ($this->buffer !== '') {
                $lines[] = $this->takeLine();
            }
        }

        return $lines;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    private function nextDelimiterOffset(string $chunk, int $offset): ?int
    {
        $lf = strpos($chunk, "\n", $offset);
        $cr = strpos($chunk, "\r", $offset);

        if ($lf === false) {
            return $cr === false ? null : $cr;
        }
        if ($cr === false) {
            return $lf;
        }

        return min($lf, $cr);
    }

    private function appendChecked(string $chunk, int $offset, int $length): void
    {
        if ($length === 0) {
            return;
        }

        if (strlen($this->buffer) + $length > $this->maxLineBytes) {
            throw new \LengthException(
                "SSE line exceeded {$this->maxLineBytes} bytes",
            );
        }

        $this->buffer .= substr($chunk, $offset, $length);
    }

    private function takeLine(): string
    {
        $line = $this->buffer;
        $this->buffer = '';

        return $line;
    }
}

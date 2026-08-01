<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use PHPUnit\Framework\TestCase;

final class BoundedSseLineBufferTest extends TestCase
{
    public function test_it_rejects_a_large_segment_before_retaining_it(): void
    {
        $buffer = new BoundedSseLineBuffer(8);

        $this->expectException(\LengthException::class);
        $buffer->push("123456789\n");
    }

    public function test_it_handles_crlf_split_across_chunks(): void
    {
        $buffer = new BoundedSseLineBuffer(32);

        self::assertSame([], $buffer->push("first\r"));
        self::assertSame(['first', 'second'], $buffer->push("\nsecond\r\n"));
    }

    public function test_end_of_stream_flushes_an_unterminated_line(): void
    {
        $buffer = new BoundedSseLineBuffer(32);

        self::assertSame(['tail'], $buffer->push('tail', true));
    }

    public function test_it_reports_only_the_retained_partial_line(): void
    {
        $buffer = new BoundedSseLineBuffer(32);

        $buffer->push('partial');

        self::assertSame(7, $buffer->bufferedBytes());
        self::assertSame(['partial'], $buffer->push("\n"));
        self::assertSame(0, $buffer->bufferedBytes());
    }
}

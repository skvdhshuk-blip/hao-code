<?php

namespace Tests\Unit;

use HaoCode\Support\Filesystem\BoundedTextFileReader;
use PHPUnit\Framework\TestCase;

class BoundedTextFileReaderTest extends TestCase
{
    public function test_path_and_string_reads_share_line_window_semantics(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'haocode_bounded_read_');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, "one\ntwo\nthree\n");

            $fromPath = BoundedTextFileReader::readPath($path, $path, 2, 1);
            $fromString = BoundedTextFileReader::readString("one\ntwo\nthree\n", 'virtual.txt', 2, 1);

            $this->assertSame(['two'], $fromPath['selectedLines']);
            $this->assertSame($fromPath['selectedLines'], $fromString['selectedLines']);
            $this->assertSame(3, $fromPath['totalLines']);
            $this->assertSame($fromPath['totalLines'], $fromString['totalLines']);
            $this->assertSame($fromPath['sha256'], $fromString['sha256']);
        } finally {
            @unlink($path);
        }
    }

    public function test_read_rejects_a_result_window_over_the_shared_output_limit(): void
    {
        $result = BoundedTextFileReader::readString(
            str_repeat(str_repeat('x', 2_000)."\n", 600),
            'wide.txt',
            1,
            2_000,
        );

        $this->assertStringContainsString('Read output exceeds', $result['error'] ?? '');
    }

    public function test_read_does_not_charge_fixed_overhead_for_every_selected_line(): void
    {
        $result = BoundedTextFileReader::readString(
            str_repeat(str_repeat('x', 50)."\n", 10_000),
            'many-short-lines.txt',
            1,
            10_000,
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(10_000, $result['selectedLines'] ?? []);
    }

    public function test_read_reports_abort_without_returning_a_partial_revision(): void
    {
        $result = BoundedTextFileReader::readString(
            "one\ntwo\n",
            'aborted.txt',
            1,
            2_000,
            static fn (): bool => true,
        );

        $this->assertTrue($result['aborted'] ?? false);
        $this->assertArrayNotHasKey('sha256', $result);
    }
}

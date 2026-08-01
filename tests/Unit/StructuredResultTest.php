<?php

namespace Tests\Unit;

use HaoCode\Sdk\StructuredResult;
use PHPUnit\Framework\TestCase;

final class StructuredResultTest extends TestCase
{
    public function test_to_json_reports_encoding_failures_as_json_exceptions(): void
    {
        $result = new StructuredResult(['invalid_utf8' => "\xB1\x31"]);

        $this->expectException(\JsonException::class);
        $result->toJson();
    }
}

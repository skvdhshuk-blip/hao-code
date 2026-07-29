<?php

namespace Tests\Sdk;

use HaoCode\Sdk\ImageContentBlock;
use PHPUnit\Framework\TestCase;

class ImageContentBlockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/haocode_image_'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_relative_file_is_resolved_from_the_run_working_directory(): void
    {
        file_put_contents($this->directory.'/pixel.bin', $this->pngBytes());

        $block = ImageContentBlock::from('pixel.bin', $this->directory);

        $this->assertSame('image/png', $block['source']['media_type']);
        $this->assertSame($this->pngBytes(), base64_decode($block['source']['data'], true));
    }

    public function test_local_file_uses_detected_mime_instead_of_extension(): void
    {
        file_put_contents($this->directory.'/pixel.jpg', $this->pngBytes());

        $block = ImageContentBlock::from($this->directory.'/pixel.jpg');

        $this->assertSame('image/png', $block['source']['media_type']);
    }

    public function test_local_file_rejects_unsupported_detected_mime(): void
    {
        file_put_contents($this->directory.'/not-an-image.png', 'plain text');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type');

        ImageContentBlock::from($this->directory.'/not-an-image.png');
    }

    public function test_local_file_rejects_files_larger_than_five_mebibytes(): void
    {
        file_put_contents($this->directory.'/oversized.png', str_repeat('x', 5_242_881));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the 5 MiB limit');

        ImageContentBlock::from($this->directory.'/oversized.png');
    }

    public function test_data_uri_rejects_invalid_base64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid base64');

        ImageContentBlock::from('data:image/png;base64,%%%');
    }

    public function test_data_uri_rejects_unsupported_media_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type');

        ImageContentBlock::from('data:text/plain;base64,'.base64_encode('hello'));
    }

    public function test_data_uri_rejects_decoded_data_larger_than_five_mebibytes(): void
    {
        $encoded = base64_encode(str_repeat('x', 5_242_881));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the 5 MiB limit');

        ImageContentBlock::from('data:image/png;base64,'.$encoded);
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }
}

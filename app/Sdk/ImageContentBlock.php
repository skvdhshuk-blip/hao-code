<?php

namespace HaoCode\Sdk;

/**
 * Helper for building Anthropic-shaped image content blocks.
 *
 * Supports local file paths, URLs, pre-built block arrays, and data URIs.
 *
 * @api
 */
class ImageContentBlock
{
    private const MAX_IMAGE_BYTES = 5_242_880;

    private const MAX_BASE64_CHARACTERS = 6_990_508;

    /** @var array<string, true> */
    private const SUPPORTED_MEDIA_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true,
        'image/webp' => true,
    ];

    /**
     * Build an image content block from a source.
     *
     * Accepted source types:
     * - Local file path (e.g. '/path/to/photo.jpg')
     * - URL string (e.g. 'https://example.com/photo.jpg')
     * - Pre-built block array (passed through as-is)
     * - Data URI (e.g. 'data:image/png;base64,iVBORw0KGgo...')
     *
     * @param string|array $source
     * @param ?string $cwd Base directory for relative local paths
     * @return array{type: string, source: array<string, mixed>}
     * @api
     */
    public static function from(string|array $source, ?string $cwd = null): array
    {
        if (is_array($source)) {
            return $source;
        }

        if (str_starts_with($source, 'data:')) {
            return self::fromDataUri($source);
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return [
                'type' => 'image',
                'source' => ['type' => 'url', 'url' => $source],
            ];
        }

        $path = self::resolveLocalPath($source, $cwd);
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Image file not found or not readable: {$path}");
        }

        $size = @filesize($path);
        if (! is_int($size)) {
            throw new \RuntimeException("Failed to determine image file size: {$path}");
        }
        self::assertWithinSizeLimit($size);

        $mediaType = self::detectMediaType($path);
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read image file: {$path}");
        }
        self::assertWithinSizeLimit(strlen($data));

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => base64_encode($data),
            ],
        ];
    }

    /**
     * Build user message content from a text prompt and optional image attachments.
     *
     * When images are present, returns an array of content blocks.
     * When no images are present, returns the plain text prompt.
     *
     * @param array<int, string|array> $images
     * @param ?string $cwd Base directory for relative local paths
     * @return string|array<int, array<string, mixed>>
     * @api
     */
    public static function buildUserContent(string $prompt, array $images = [], ?string $cwd = null): string|array
    {
        if ($images === []) {
            return $prompt;
        }

        $blocks = [];
        if ($prompt !== '') {
            $blocks[] = ['type' => 'text', 'text' => $prompt];
        }

        foreach ($images as $image) {
            $blocks[] = self::from($image, $cwd);
        }

        return $blocks;
    }

    private static function fromDataUri(string $uri): array
    {
        if (! preg_match('/\Adata:([^;,]+);base64,(.+)\z/sD', $uri, $matches)) {
            throw new \InvalidArgumentException('Invalid data URI format. Expected: data:<media_type>;base64,<data>');
        }

        $mediaType = strtolower($matches[1]);
        self::assertSupportedMediaType($mediaType);
        if (strlen($matches[2]) > self::MAX_BASE64_CHARACTERS) {
            throw new \InvalidArgumentException('Image exceeds the 5 MiB limit.');
        }

        $decoded = base64_decode($matches[2], true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Image data URI must contain valid base64 data.');
        }
        self::assertWithinSizeLimit(strlen($decoded));

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $matches[2],
            ],
        ];
    }

    private static function detectMediaType(string $path): string
    {
        if (! class_exists(\finfo::class)) {
            throw new \RuntimeException('The fileinfo extension is required for local image validation.');
        }

        $mediaType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mediaType) || $mediaType === '') {
            throw new \InvalidArgumentException("Unable to detect image MIME type: {$path}");
        }
        $mediaType = strtolower($mediaType);
        self::assertSupportedMediaType($mediaType);

        return $mediaType;
    }

    private static function resolveLocalPath(string $path, ?string $cwd): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        $base = $cwd;
        if ($base === null || trim($base) === '') {
            $base = getcwd() ?: '.';
        }

        return rtrim($base, '/\\').DIRECTORY_SEPARATOR.$path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private static function assertSupportedMediaType(string $mediaType): void
    {
        if (! isset(self::SUPPORTED_MEDIA_TYPES[$mediaType])) {
            throw new \InvalidArgumentException("Unsupported image MIME type: {$mediaType}");
        }
    }

    private static function assertWithinSizeLimit(int $bytes): void
    {
        if ($bytes > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException('Image exceeds the 5 MiB limit.');
        }
    }
}

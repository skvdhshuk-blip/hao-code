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
     * @return array{type: string, source: array<string, mixed>}
     * @api
     */
    public static function from(string|array $source): array
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

        if (! file_exists($source) || ! is_readable($source)) {
            throw new \InvalidArgumentException("Image file not found or not readable: {$source}");
        }

        $data = file_get_contents($source);
        if ($data === false) {
            throw new \RuntimeException("Failed to read image file: {$source}");
        }

        $mediaType = self::detectMediaType($source);

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
     * @return string|array<int, array<string, mixed>>
     * @api
     */
    public static function buildUserContent(string $prompt, array $images = []): string|array
    {
        if ($images === []) {
            return $prompt;
        }

        $blocks = [];
        if ($prompt !== '') {
            $blocks[] = ['type' => 'text', 'text' => $prompt];
        }

        foreach ($images as $image) {
            $blocks[] = self::from($image);
        }

        return $blocks;
    }

    private static function fromDataUri(string $uri): array
    {
        if (! preg_match('/^data:([^;]+);base64,(.+)$/', $uri, $matches)) {
            throw new \InvalidArgumentException('Invalid data URI format. Expected: data:<media_type>;base64,<data>');
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $matches[1],
                'data' => $matches[2],
            ],
        ];
    }

    private static function detectMediaType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            default => 'image/jpeg',
        };
    }
}

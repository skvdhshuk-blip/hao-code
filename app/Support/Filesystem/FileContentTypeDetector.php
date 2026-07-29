<?php

namespace HaoCode\Support\Filesystem;

/**
 * Minimal text-tool boundary detection that does not depend on fileinfo.
 *
 * @internal
 */
final class FileContentTypeDetector
{
    /** @return 'image'|'pdf'|null */
    public static function detect(
        string $path,
        string $prefix = '',
        ?string $mimeType = null,
    ): ?string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = strtolower(trim((string) $mimeType));

        if ($extension === 'pdf'
            || in_array($mimeType, ['application/pdf', 'application/x-pdf'], true)
            || self::hasPdfSignature($prefix)
        ) {
            return 'pdf';
        }

        if (str_starts_with($mimeType, 'image/')
            || in_array($extension, [
                'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'avif',
                'tif', 'tiff', 'ico', 'heic', 'heif',
            ], true)
            || self::hasImageSignature($prefix)
        ) {
            return 'image';
        }

        return null;
    }

    private static function hasPdfSignature(string $prefix): bool
    {
        $position = strpos(substr($prefix, 0, 1024), '%PDF-');

        return $position !== false;
    }

    private static function hasImageSignature(string $prefix): bool
    {
        if (str_starts_with($prefix, "\x89PNG\r\n\x1A\n")
            || str_starts_with($prefix, "\xFF\xD8\xFF")
            || str_starts_with($prefix, 'GIF87a')
            || str_starts_with($prefix, 'GIF89a')
            || str_starts_with($prefix, 'BM')
            || str_starts_with($prefix, "II*\x00")
            || str_starts_with($prefix, "MM\x00*")
            || str_starts_with($prefix, "\x00\x00\x01\x00")
            || (str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP')
        ) {
            return true;
        }

        if (substr($prefix, 4, 4) === 'ftyp'
            && in_array(substr($prefix, 8, 4), [
                'avif', 'avis', 'heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1',
            ], true)
        ) {
            return true;
        }

        return preg_match(
            '/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i',
            substr($prefix, 0, 1024),
        ) === 1;
    }
}

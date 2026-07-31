<?php

declare(strict_types=1);

namespace HaoCode\Support\Filesystem;

/**
 * Resolve user-supplied filesystem paths before permission checks.
 *
 * @internal
 */
final class CanonicalPathResolver
{
    public static function isFilesystemRoot(string $path): bool
    {
        if (self::isWindowsAbsolute($path)) {
            $normalized = self::normalizeWindowsPath($path);

            return preg_match('/^[A-Za-z]:\\\\$/', $normalized) === 1
                || preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+$/', $normalized) === 1;
        }

        if (self::isUnixAbsolute($path) && self::normalizeUnixPath($path) === '/') {
            return true;
        }

        $canonical = realpath($path);

        return is_string($canonical)
            && $canonical !== $path
            && self::isFilesystemRoot($canonical);
    }

    public static function resolve(string $path, string $workingDirectory): string
    {
        $path = self::expandHome($path);
        $workingDirectory = self::expandHome($workingDirectory);

        if (self::isWindowsAbsolute($path)) {
            return self::resolveWindowsPath($path);
        }

        if ((PHP_OS_FAMILY === 'Windows' || self::isWindowsAbsolute($workingDirectory))
            && self::isWindowsRootRelative($path)
        ) {
            return self::resolveWindowsPath(
                self::windowsDrive($workingDirectory).ltrim($path, '/\\'),
            );
        }

        if (! self::isUnixAbsolute($path)) {
            if (self::isWindowsAbsolute($workingDirectory)) {
                return self::resolveWindowsPath(
                    rtrim($workingDirectory, '/\\').'\\'.$path,
                );
            }

            if (! self::isUnixAbsolute($workingDirectory)) {
                $workingDirectory = rtrim((string) (getcwd() ?: '.'), '/')
                    .'/'.$workingDirectory;
            }
            $path = rtrim($workingDirectory, '/').'/'.$path;
        }

        return self::resolveNearestExistingUnixAncestor($path);
    }

    /** @internal */
    public static function isWithin(string $path, string $root): bool
    {
        $pathIsWindows = self::isWindowsAbsolute($path);
        $rootIsWindows = self::isWindowsAbsolute($root);
        if ($pathIsWindows !== $rootIsWindows) {
            return false;
        }

        if ($pathIsWindows) {
            $path = self::normalizeWindowsPath($path);
            $root = self::normalizeWindowsPath($root);
            if (strcasecmp($path, $root) === 0) {
                return true;
            }

            $prefix = rtrim($root, '\\').'\\';

            return strncasecmp($path, $prefix, strlen($prefix)) === 0;
        }

        if (! self::isUnixAbsolute($path) || ! self::isUnixAbsolute($root)) {
            return false;
        }

        $path = self::normalizeUnixPath($path);
        $root = self::normalizeUnixPath($root);
        if ($path === $root) {
            return true;
        }

        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private static function expandHome(string $path): string
    {
        if ($path !== '~' && ! str_starts_with($path, '~/') && ! str_starts_with($path, '~\\')) {
            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
        if (PHP_OS_FAMILY === 'Windows'
            && (! is_string($home) || $home === '' || ! is_dir($home))
        ) {
            $home = getenv('USERPROFILE') ?: null;
            if (! is_string($home) || $home === '') {
                $homeDrive = getenv('HOMEDRIVE');
                $homePath = getenv('HOMEPATH');
                $home = is_string($homeDrive) && $homeDrive !== ''
                    && is_string($homePath) && $homePath !== ''
                    ? $homeDrive.$homePath
                    : null;
            }
        }
        if (! is_string($home) || $home === '') {
            return $path;
        }

        if ($path === '~') {
            return rtrim($home, '/\\') ?: DIRECTORY_SEPARATOR;
        }

        return (rtrim($home, '/\\') ?: DIRECTORY_SEPARATOR).substr($path, 1);
    }

    private static function isUnixAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }

    private static function isWindowsAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || preg_match('#^//[^/]#', $path) === 1;
    }

    private static function isWindowsRootRelative(string $path): bool
    {
        return (str_starts_with($path, '/') || str_starts_with($path, '\\'))
            && ! str_starts_with($path, '\\\\')
            && preg_match('#^//[^/]#', $path) !== 1;
    }

    private static function windowsDrive(string $workingDirectory): string
    {
        foreach ([$workingDirectory, (string) (getcwd() ?: '')] as $candidate) {
            if (preg_match('/^([A-Za-z]):[\/\\\\]/', $candidate, $matches) === 1) {
                return strtoupper($matches[1]).':\\';
            }
        }

        return 'C:\\';
    }

    private static function normalizeUnixPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Resolve the nearest existing ancestor so a non-existent destination
     * below a symlink is still represented by its physical target.
     */
    private static function resolveNearestExistingUnixAncestor(string $path): string
    {
        $candidate = $path;
        $suffix = [];

        while (($real = realpath($candidate)) === false) {
            if ($candidate === '/') {
                return self::normalizeUnixPath($path);
            }

            array_unshift($suffix, basename($candidate));
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return self::normalizeUnixPath($path);
            }
            $candidate = $parent;
        }

        if ($suffix === []) {
            return $real;
        }

        return self::normalizeUnixPath(
            rtrim($real, '/').'/'.implode('/', $suffix),
        );
    }

    private static function resolveWindowsPath(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return self::normalizeWindowsPath($path);
        }

        $candidate = str_replace('/', '\\', $path);
        $suffix = [];
        while (($real = realpath($candidate)) === false) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return self::normalizeWindowsPath($path);
            }
            array_unshift($suffix, basename($candidate));
            $candidate = $parent;
        }

        return $suffix === []
            ? $real
            : self::normalizeWindowsPath(
                rtrim($real, '\\').'\\'.implode('\\', $suffix),
            );
    }

    private static function normalizeWindowsPath(string $path): string
    {
        $path = str_replace('/', '\\', $path);
        $root = '';
        $remainder = $path;

        if (preg_match('/^([A-Za-z]):\\\\(.*)$/', $path, $matches) === 1) {
            $root = strtoupper($matches[1]).':\\';
            $remainder = $matches[2];
        } elseif (preg_match('/^\\\\\\\\([^\\\\]+)\\\\([^\\\\]+)(?:\\\\(.*))?$/', $path, $matches) === 1) {
            $root = '\\\\'.$matches[1].'\\'.$matches[2];
            $remainder = $matches[3] ?? '';
        }

        $segments = [];
        foreach (explode('\\', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return $root;
        }

        return rtrim($root, '\\').'\\'.implode('\\', $segments);
    }
}

<?php

use HaoCode\Support\Runtime\SdkRuntime;

if (! function_exists('app')) {
    function app(?string $abstract = null): mixed
    {
        return SdkRuntime::app($abstract);
    }
}

if (! function_exists('config')) {
    function config(null|string|array $key = null, mixed $default = null): mixed
    {
        $app = SdkRuntime::app();

        if (is_array($key)) {
            foreach ($key as $name => $value) {
                $app->setConfig((string) $name, $value);
            }

            return null;
        }

        return $app->config($key, $default);
    }
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }
}

if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return SdkRuntime::app()->basePath($path);
    }
}

if (! function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return SdkRuntime::app()->appPath($path);
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return SdkRuntime::app()->configPath($path);
    }
}

if (! function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return SdkRuntime::app()->resourcePath($path);
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return SdkRuntime::app()->storagePath($path);
    }
}

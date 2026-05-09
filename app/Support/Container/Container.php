<?php

namespace HaoCode\Support\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

class Container
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, mixed> */
    private array $config = [];

    private string $storagePath;

    public function __construct(
        private readonly string $basePath,
    ) {
        $this->storagePath = $this->basePath.'/storage';
    }

    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => true,
        ];
    }

    public function bind(string $abstract, mixed $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => false,
        ];
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    public function bound(string $abstract): bool
    {
        return array_key_exists($abstract, $this->bindings)
            || array_key_exists($abstract, $this->instances);
    }

    public function make(string $abstract): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;
        $object = $this->build($concrete);

        if (($binding['shared'] ?? false) === true) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    public function useStoragePath(string $path): void
    {
        $this->storagePath = rtrim($path, '/');
        $this->setConfig('haocode.session_path', $this->storagePath('app/haocode/sessions'));
    }

    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    public function appPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath.'/app', $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath.'/config', $path);
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath.'/resources', $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->storagePath, $path);
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function setConfig(string $key, mixed $value): void
    {
        $target = &$this->config;
        foreach (explode('.', $key) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function mergeConfig(string $namespace, array $values): void
    {
        $existing = $this->config[$namespace] ?? [];
        $this->config[$namespace] = array_replace_recursive(
            is_array($existing) ? $existing : [],
            $values,
        );
    }

    private function build(mixed $concrete): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        if (is_object($concrete) && ! is_string($concrete)) {
            return $concrete;
        }

        if (! is_string($concrete) || ! class_exists($concrete)) {
            throw new \RuntimeException("Unable to resolve [{$concrete}].");
        }

        $reflection = new ReflectionClass($concrete);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $arguments[] = $this->make($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new \RuntimeException(
                sprintf('Unable to resolve parameter $%s for [%s].', $parameter->getName(), $concrete)
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function joinPath(string $base, string $path): string
    {
        return $path === '' ? rtrim($base, '/') : rtrim($base, '/').'/'.ltrim($path, '/');
    }
}

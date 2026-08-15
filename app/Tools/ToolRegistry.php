<?php

declare(strict_types=1);

namespace HaoCode\Tools;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Sdk\SdkTool;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> */
    private array $apiSchemas = [];

    /** @var array<string, bool> */
    private array $cacheableSchemas = [];

    public function register(ToolInterface $tool): void
    {
        $name = $this->validatedName($tool);
        if (isset($this->tools[$name])) {
            throw new \LogicException(
                "Tool '{$name}' is already registered; use replace() for an intentional implementation change.",
            );
        }

        $this->install($name, $tool);
    }

    /**
     * Replace one known registration without making accidental name collisions
     * indistinguishable from framework-owned backend substitution.
     *
     * @internal
     */
    public function replace(ToolInterface $tool): void
    {
        $name = $this->validatedName($tool);
        if (! isset($this->tools[$name])) {
            throw new \LogicException("Tool '{$name}' is not registered and cannot be replaced.");
        }

        $this->install($name, $tool);
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getAllTools(): array
    {
        return array_filter(
            $this->tools,
            static fn (ToolInterface $tool): bool => $tool->isEnabled(),
        );
    }

    /**
     * Remove a tool by name (e.g. for dynamic MCP tool cleanup).
     */
    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
        unset($this->apiSchemas[$name]);
        unset($this->cacheableSchemas[$name]);
    }

    /**
     * Check if a tool with the given name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Convert all enabled tools to Anthropic API tool format.
     */
    public function toApiTools(): array
    {
        $apiTools = [];
        foreach ($this->getAllTools() as $name => $tool) {
            $this->assertStableIdentity($name, $tool);
            $schema = $this->schemaFor($name, $tool);

            $apiTools[] = [
                'name' => $name,
                'description' => $tool->description(),
                'input_schema' => $schema,
            ];
        }

        return $apiTools;
    }

    /**
     * Validated run manifest consumed by capability preflight.
     *
     * Effect remains invocation-scoped because isReadOnly() depends on input.
     * Claiming a static read/write effect here would create a second, weaker
     * permission fact.
     *
     * @return array<string, array{name: string, description: string, input_schema: array<string, mixed>, effect: string, implementation: string}>
     * @internal
     */
    public function manifest(): array
    {
        $manifest = [];
        foreach ($this->getAllTools() as $name => $tool) {
            $this->assertStableIdentity($name, $tool);
            $manifest[$name] = [
                'name' => $name,
                'description' => $tool->description(),
                'input_schema' => $this->schemaFor($name, $tool),
                'effect' => 'runtime',
                'implementation' => $this->implementationId($tool),
            ];
        }

        ksort($manifest);

        return $manifest;
    }

    private function install(string $name, ToolInterface $tool): void
    {
        $this->validateSchema($name, $tool->inputSchema()->toJsonSchema());
        $this->tools[$name] = $tool;
        unset($this->apiSchemas[$name]);
        $this->cacheableSchemas[$name] = $this->isSchemaCacheable($tool);
    }

    private function validatedName(ToolInterface $tool): string
    {
        $name = $tool->name();
        if ($name === '' || trim($name) !== $name || strlen($name) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Tool names must be 1-128 characters using letters, numbers, dot, underscore, colon, or hyphen.',
            );
        }

        return $name;
    }

    private function assertStableIdentity(string $registeredName, ToolInterface $tool): void
    {
        if ($tool->name() !== $registeredName) {
            throw new \LogicException(
                "Registered tool identity '{$registeredName}' changed to '{$tool->name()}'; register a new tool instance instead.",
            );
        }
    }

    /** @return array<string, mixed> */
    private function schemaFor(string $name, ToolInterface $tool): array
    {
        $schema = ($this->cacheableSchemas[$name] ?? false)
            ? ($this->apiSchemas[$name] ??= $tool->inputSchema()->toJsonSchema())
            : $tool->inputSchema()->toJsonSchema();
        $this->validateSchema($name, $schema);

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function validateSchema(string $name, array $schema): void
    {
        if (($schema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException("Tool '{$name}' input schema must have type=object.");
        }
        if (isset($schema['properties']) && ! is_array($schema['properties']) && ! is_object($schema['properties'])) {
            throw new \InvalidArgumentException("Tool '{$name}' input schema properties must be an object map.");
        }
        if (isset($schema['required']) && ! is_array($schema['required'])) {
            throw new \InvalidArgumentException("Tool '{$name}' input schema required must be an array.");
        }
        if (isset($schema['required'])) {
            $properties = (array) ($schema['properties'] ?? []);
            if (! array_is_list($schema['required'])) {
                throw new \InvalidArgumentException("Tool '{$name}' input schema required must be a list.");
            }
            foreach ($schema['required'] as $requiredName) {
                if (! is_string($requiredName) || ! array_key_exists($requiredName, $properties)) {
                    throw new \InvalidArgumentException(
                        "Tool '{$name}' input schema required entries must name declared properties.",
                    );
                }
            }
        }
    }

    private function implementationId(ToolInterface $tool): string
    {
        $class = $tool::class;
        if (str_contains($class, '@anonymous')) {
            // Anonymous class identities include their source path. The
            // capability manifest is observable through the SDK, so expose a
            // stable opaque identity instead of leaking a local filesystem.
            return 'anonymous:'.hash('sha256', $class);
        }

        return $class;
    }

    /**
     * Only package-owned tools have a known-stable schema. Third-party ToolInterface
     * and BaseTool implementations retain the pre-cache behavior of being evaluated
     * on every API conversion, just like the public SdkTool extension point.
     */
    private function isSchemaCacheable(ToolInterface $tool): bool
    {
        if ($tool instanceof SdkTool) {
            return false;
        }

        $file = (new \ReflectionClass($tool))->getFileName();
        if ($file === false) {
            return false;
        }

        $appDirectory = realpath(dirname(__DIR__));
        $classFile = realpath($file);

        return $appDirectory !== false
            && $classFile !== false
            && str_starts_with($classFile, $appDirectory.DIRECTORY_SEPARATOR);
    }
}

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
        $name = $tool->name();
        $this->tools[$name] = $tool;
        unset($this->apiSchemas[$name]);
        $this->cacheableSchemas[$name] = $this->isSchemaCacheable($tool);
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
            $schema = ($this->cacheableSchemas[$name] ?? false)
                ? ($this->apiSchemas[$name] ??= $tool->inputSchema()->toJsonSchema())
                : $tool->inputSchema()->toJsonSchema();

            $apiTools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $schema,
            ];
        }

        return $apiTools;
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

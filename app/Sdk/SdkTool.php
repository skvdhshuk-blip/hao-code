<?php

namespace HaoCode\Sdk;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * Simplified base class for SDK consumers to define custom tools.
 *
 * Implement only 4 methods: name(), description(), parameters(), handle().
 * Everything else (schema, permissions, concurrency) has sensible defaults.
 *
 * @example
 *   class LookupOrderTool extends SdkTool {
 *       public function name(): string { return 'LookupOrder'; }
 *       public function description(): string { return 'Look up an order by ID'; }
 *       public function parameters(): array {
 *           return [
 *               'order_id' => ['type' => 'string', 'description' => 'The order ID', 'required' => true],
 *           ];
 *       }
 *       public function handle(array $input): string {
 *           return Order::find($input['order_id'])->toJson();
 *       }
 *   }
 *
 * @api
 */
abstract class SdkTool extends BaseTool
{
    /**
     * Tool name as it appears to the model.
     *
     * @api
     */
    abstract public function name(): string;

    /**
     * Description shown to the model to explain when to use this tool.
     *
     * @api
     */
    abstract public function description(): string;

    /**
     * Define input parameters as a simplified associative array.
     *
     * Each key is the parameter name. Value is an array with:
     *   'type'        => 'string'|'integer'|'number'|'boolean'|'array'|'object' (default: 'string')
     *   'description' => string (optional)
     *   'required'    => bool (default: false)
     *   'enum'        => string[] (optional)
     *
     * @api
     *
     * @return array<string, array{type?: string, description?: string, required?: bool, enum?: string[]}>
     */
    abstract public function parameters(): array;

    /**
     * Execute the tool and return a string result.
     *
     * @api
     *
     * @param  array<string, mixed>  $input  Validated input matching parameters().
     * @return string The tool's output shown to the model.
     *
     * @throws \Throwable Any exception is caught and returned as a ToolResult error.
     */
    abstract public function handle(array $input): string;

    /**
     * @internal
     */
    public function inputSchema(): ToolInputSchema
    {
        $properties = [];
        $required = [];
        $rules = [];

        foreach ($this->parameters() as $name => $param) {
            $type = $param['type'] ?? 'string';

            $prop = ['type' => $type];
            if (isset($param['description'])) {
                $prop['description'] = $param['description'];
            }
            if (isset($param['enum'])) {
                $prop['enum'] = $param['enum'];
            }

            $properties[$name] = $prop;

            // JSON Schema types don't all line up 1:1 with Laravel validator
            // rule names — translate `number` and `object` explicitly.
            $laravelType = match ($type) {
                'number' => 'numeric',
                'object' => 'array',
                default => $type,
            };

            if ($param['required'] ?? false) {
                $required[] = $name;
                $rules[$name] = 'required|'.$laravelType;
            } else {
                $rules[$name] = 'nullable|'.$laravelType;
            }
        }

        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], $rules);
    }

    /**
     * @internal
     */
    public function call(array $input, ToolUseContext $context): ToolResult
    {
        try {
            $output = $this->handle($input);

            return ToolResult::success($output);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @internal
     */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}

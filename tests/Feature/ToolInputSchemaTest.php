<?php

namespace Tests\Feature;

use HaoCode\Tools\ToolInputSchema;
use Tests\TestCase;

class ToolInputSchemaTest extends TestCase
{
    // ─── make / toJsonSchema ──────────────────────────────────────────────

    public function test_to_json_schema_returns_schema(): void
    {
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]);

        $raw = $schema->toJsonSchema();
        $this->assertSame('object', $raw['type']);
        $this->assertArrayHasKey('q', $raw['properties']);
    }

    public function test_make_stores_json_schema(): void
    {
        $def = ['type' => 'object', 'properties' => []];
        $schema = ToolInputSchema::make($def);
        $this->assertSame($def, $schema->toJsonSchema());
    }

    // ─── validate — no rules passes through ───────────────────────────────

    public function test_validate_with_no_rules_returns_input(): void
    {
        // Neither rules nor a constraining jsonSchema → passthrough.
        $schema = ToolInputSchema::make(['type' => 'object'], []);
        $input = ['anything' => 'goes'];
        $this->assertSame($input, $schema->validate($input));
    }

    public function test_validate_falls_back_to_json_schema_when_rules_empty(): void
    {
        // chatgpt #10: with no Laravel-style rules but a jsonSchema declaring
        // required + enum, the validator must still enforce them via swaggest.
        // This is the path MCP tools and most built-in tools rely on.
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'required' => ['mode'],
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['read', 'write']],
            ],
        ]);

        // Valid input passes.
        $this->assertSame(['mode' => 'read'], $schema->validate(['mode' => 'read']));

        // Missing required field is rejected.
        try {
            $schema->validate([]);
            $this->fail('Expected validation failure for missing required field');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Tool input validation failed', $e->getMessage());
        }

        // Enum violation is rejected.
        try {
            $schema->validate(['mode' => 'execute']);
            $this->fail('Expected validation failure for enum violation');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('enum', strtolower($e->getMessage()));
        }
    }

    public function test_validate_with_nested_json_schema_enforces_item_types(): void
    {
        // Nested objects + array item types must also be enforced by swaggest.
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);

        // Valid (array of strings).
        $this->assertSame(['items' => ['a', 'b']], $schema->validate(['items' => ['a', 'b']]));

        // Invalid (array of integers).
        try {
            $schema->validate(['items' => [1, 2]]);
            $this->fail('Expected validation failure for array item type mismatch');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Tool input validation failed', $e->getMessage());
        }
    }

    public function test_validate_with_malformed_json_schema_silently_allows(): void
    {
        // A broken self-contained schema must NOT break every call to the tool.
        // Keep the MCP compatibility fallback without performing remote I/O.
        $schema = ToolInputSchema::make([
            'type' => 'object',
            '$ref' => '#/$defs/missing',
        ]);

        // Should not throw — silent allow.
        $result = $schema->validate(['anything' => 'goes']);
        $this->assertSame(['anything' => 'goes'], $result);
    }

    public function test_validate_supports_self_contained_fragment_refs(): void
    {
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'required' => ['payload'],
            'properties' => [
                'payload' => ['$ref' => '#/definitions/payload'],
            ],
            'definitions' => [
                'payload' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);

        $valid = ['payload' => ['name' => 'safe']];
        $this->assertSame($valid, $schema->validate($valid));

        try {
            $schema->validate(['payload' => []]);
            $this->fail('Expected local fragment schema validation to reject invalid data.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Tool input validation failed',
                $exception->getMessage(),
            );
        }
    }

    public function test_validate_allows_ref_as_a_property_name(): void
    {
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'required' => ['$ref'],
            'properties' => [
                '$ref' => ['type' => 'string'],
            ],
        ]);

        $input = ['$ref' => 'literal property value'];
        $this->assertSame($input, $schema->validate($input));
    }

    public function test_validate_does_not_resolve_ref_shaped_literal_data(): void
    {
        $scheme = 'haocode-schema-literal-probe';
        $this->assertNotContains($scheme, stream_get_wrappers());
        $this->assertTrue(stream_wrapper_register($scheme, SchemaProbeStreamWrapper::class));
        SchemaProbeStreamWrapper::$openCalls = 0;
        $literal = ['$ref' => "{$scheme}://internal.example/not-a-schema.json"];

        try {
            $schema = ToolInputSchema::make([
                'type' => 'object',
                'required' => ['payload'],
                'properties' => [
                    'payload' => [
                        'const' => $literal,
                        'default' => $literal,
                        'examples' => [$literal],
                    ],
                ],
            ]);

            $input = ['payload' => $literal];
            $this->assertSame($input, $schema->validate($input));
            $this->assertSame(0, SchemaProbeStreamWrapper::$openCalls);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_validate_keeps_fragment_refs_local_under_an_external_id(): void
    {
        $scheme = 'haocode-schema-id-probe';
        $this->assertNotContains($scheme, stream_get_wrappers());
        $this->assertTrue(stream_wrapper_register($scheme, SchemaProbeStreamWrapper::class));
        SchemaProbeStreamWrapper::$openCalls = 0;

        try {
            $schema = ToolInputSchema::make([
                '$id' => "{$scheme}://internal.example/root.json",
                'type' => 'object',
                'required' => ['payload'],
                'properties' => [
                    'payload' => ['$ref' => '#/definitions/payload'],
                ],
                'definitions' => [
                    'payload' => ['type' => 'string'],
                ],
            ]);

            $valid = ['payload' => 'safe'];
            $this->assertSame($valid, $schema->validate($valid));

            try {
                $schema->validate(['payload' => 123]);
                $this->fail('Expected local fragment validation to reject invalid data.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'Tool input validation failed',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(0, SchemaProbeStreamWrapper::$openCalls);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_validate_rejects_relative_ref_under_external_id_without_io(): void
    {
        $scheme = 'haocode-schema-relative-probe';
        $this->assertNotContains($scheme, stream_get_wrappers());
        $this->assertTrue(stream_wrapper_register($scheme, SchemaProbeStreamWrapper::class));
        SchemaProbeStreamWrapper::$openCalls = 0;

        try {
            $schema = ToolInputSchema::make([
                '$id' => "{$scheme}://internal.example/root.json",
                'type' => 'object',
                'properties' => [
                    'payload' => ['$ref' => 'child.json'],
                ],
            ]);

            try {
                $schema->validate(['payload' => []]);
                $this->fail('Expected relative schema reference to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'External JSON Schema references are not supported',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(0, SchemaProbeStreamWrapper::$openCalls);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_validate_rejects_external_schema_refs_without_opening_them(): void
    {
        $scheme = 'haocode-schema-probe';
        $this->assertNotContains($scheme, stream_get_wrappers());
        $this->assertTrue(stream_wrapper_register($scheme, SchemaProbeStreamWrapper::class));
        SchemaProbeStreamWrapper::$openCalls = 0;

        try {
            $schema = ToolInputSchema::make([
                'type' => 'object',
                'properties' => [
                    'payload' => [
                        '$ref' => "{$scheme}://internal.example/schema.json",
                    ],
                ],
            ]);

            try {
                $schema->validate(['payload' => []]);
                $this->fail('Expected external schema reference to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'External JSON Schema references are not supported',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(0, SchemaProbeStreamWrapper::$openCalls);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_validate_rejects_file_and_relative_schema_refs(): void
    {
        foreach (['file:///etc/passwd', '../schema.json', '//internal/schema.json'] as $ref) {
            $schema = ToolInputSchema::make([
                'type' => 'object',
                '$ref' => $ref,
            ]);

            try {
                $schema->validate([]);
                $this->fail("Expected external schema reference to be rejected: {$ref}");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'External JSON Schema references are not supported',
                    $exception->getMessage(),
                );
            }
        }
    }

    // ─── validate — with rules ────────────────────────────────────────────

    public function test_validate_required_field_passes(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['query' => 'required|string'],
        );
        $result = $schema->validate(['query' => 'hello']);
        $this->assertSame('hello', $result['query']);
    }

    public function test_validate_missing_required_field_throws(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['query' => 'required|string'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/validation failed/i');
        $schema->validate([]);
    }

    public function test_validate_wrong_type_throws(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 'not-an-int']);
    }

    public function test_validate_min_rule_enforced(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer|min:1'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 0]);
    }

    public function test_validate_max_rule_enforced(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer|max:100'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 200]);
    }

    public function test_validate_nullable_field_allows_null(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['format' => 'nullable|string'],
        );

        $result = $schema->validate(['format' => null]);
        $this->assertNull($result['format']);
    }

    public function test_validate_in_rule_accepts_valid_value(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['mode' => 'required|string|in:fast,slow'],
        );

        $result = $schema->validate(['mode' => 'fast']);
        $this->assertSame('fast', $result['mode']);
    }

    public function test_validate_in_rule_rejects_invalid_value(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['mode' => 'required|string|in:fast,slow'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['mode' => 'turbo']);
    }

    public function test_validate_url_rule_accepts_valid_url(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['url' => 'required|url'],
        );

        $result = $schema->validate(['url' => 'https://example.com']);
        $this->assertSame('https://example.com', $result['url']);
    }

    public function test_validate_url_rule_rejects_non_url(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['url' => 'required|url'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['url' => 'not-a-url']);
    }

    public function test_validate_exception_message_mentions_first_error(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['name' => 'required|string', 'age' => 'required|integer'],
        );

        try {
            $schema->validate([]);
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('validation failed', strtolower($e->getMessage()));
        }
    }
}

final class SchemaProbeStreamWrapper
{
    public mixed $context;

    public static int $openCalls = 0;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$openCalls++;

        return false;
    }
}

<?php

namespace HaoCode\Tools;

use HaoCode\Support\JsonSchema\DenyRemoteRefProvider;
use HaoCode\Support\JsonSchema\ExternalReferenceException;
use Swaggest\JsonSchema\Context;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class ToolInputSchema
{
    private ?Schema $compiledSchema = null;

    public function __construct(
        private readonly array $jsonSchema,
    ) {}

    /**
     * Create from a raw JSON Schema definition.
     */
    public static function make(array $jsonSchema): self
    {
        return new self($jsonSchema);
    }

    /**
     * Get the JSON Schema for the Anthropic API.
     */
    public function toJsonSchema(): array
    {
        return $this->jsonSchema;
    }

    /**
     * Validate runtime input against the compiled JSON Schema.
     */
    public function validate(array $input): array
    {
        $this->assertValidDefinition();
        $data = $input === []
            ? new \stdClass()
            : json_decode((string) json_encode($input, JSON_UNESCAPED_SLASHES));

        try {
            $this->compiledSchema?->in($data, new Context(new DenyRemoteRefProvider));
        } catch (InvalidValue $exception) {
            throw new \InvalidArgumentException(
                'Tool input validation failed: '.trim($exception->getMessage()),
                previous: $exception,
            );
        }

        return $input;
    }

    /**
     * Compile the complete definition before a tool can be advertised or run.
     *
     * @internal
     */
    public function assertValidDefinition(): void
    {
        if ($this->compiledSchema !== null) {
            return;
        }

        try {
            $schema = json_decode((string) json_encode(
                $this->normalizeObjectFields($this->jsonSchema),
                JSON_UNESCAPED_SLASHES,
            ));
            $this->compiledSchema = Schema::import($schema, new Context(new DenyRemoteRefProvider));
        } catch (ExternalReferenceException $exception) {
            throw new \InvalidArgumentException(
                'External JSON Schema references are not supported; '
                .'only local fragment references beginning with "#" are allowed.',
                previous: $exception,
            );
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                'Invalid JSON Schema definition: '.trim($exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * JSON Schema fields that must always be objects, never arrays. When a
     * caller writes an empty PHP array for these, json_encode would emit `[]`
     * and swaggest rejects the schema. Coerce to stdClass so `{}` is emitted.
     */
    private const OBJECT_TYPED_SCHEMA_FIELDS = [
        'properties', 'patternProperties', 'definitions', '$defs',
        'dependencies', '$dependencies',
    ];

    private function normalizeObjectFields(mixed $value): mixed
    {
        if (is_array($value)) {
            // Empty array that is NOT in an object-typed field context still
            // needs coercion when it sits at one of those keys. Walk first.
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = in_array($k, self::OBJECT_TYPED_SCHEMA_FIELDS, true) && is_array($v) && $v === []
                    ? new \stdClass()
                    : $this->normalizeObjectFields($v);
            }

            return $out;
        }

        return $value;
    }

}

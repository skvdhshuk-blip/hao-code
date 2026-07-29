<?php

namespace HaoCode\Tools;

class ToolInputSchema
{
    public function __construct(
        private readonly array $jsonSchema,
        private readonly array $validationRules = [],
    ) {}

    /**
     * Create from a raw JSON Schema definition.
     */
    public static function make(array $jsonSchema, array $validationRules = []): self
    {
        return new self($jsonSchema, $validationRules);
    }

    /**
     * Get the JSON Schema for the Anthropic API.
     */
    public function toJsonSchema(): array
    {
        return $this->jsonSchema;
    }

    /**
     * Validate input against the schema rules.
     *
     * When $validationRules is non-empty, runs the Laravel-style rule pipeline
     * (SdkTool and legacy callers). When empty but $jsonSchema is non-empty,
     * falls back to a real JSON Schema validator (swaggest) so MCP dynamic
     * tools and the 47 built-in tools that pass only a JSON Schema still get
     * required/enum/type/nested-object/array-item validation. Schema parse
     * failures in self-contained schemas degrade gracefully to "allow" so one
     * bad MCP server cannot take down every tool call. Schema import uses a
     * deny-all resolver so external references cannot perform network or local
     * filesystem I/O on behalf of an untrusted schema.
     */
    public function validate(array $input): array
    {
        if (! empty($this->validationRules)) {
            foreach ($this->validationRules as $attribute => $rules) {
                $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
                foreach ($this->valuesForAttribute($input, (string) $attribute) as [$label, $exists, $value]) {
                    $error = $this->validateValue($label, $exists, $value, $rules);
                    if ($error !== null) {
                        throw new \InvalidArgumentException('Tool input validation failed: '.$error);
                    }
                }
            }

            return $input;
        }

        if (empty($this->jsonSchema)) {
            return $input;
        }

        $errors = $this->validateWithJsonSchema($input);
        if ($errors !== []) {
            throw new \InvalidArgumentException('Tool input validation failed: '.implode('; ', $errors));
        }

        return $input;
    }

    /**
     * Validate against $jsonSchema using swaggest. Returns a list of
     * human-readable error strings (empty when valid). Schema parse failures
     * return an empty list (silent allow) — see validate() docblock.
     *
     * @return list<string>
     */
    private function validateWithJsonSchema(array $input): array
    {
        try {
            // Normalize the schema so empty object-typed fields (properties,
            // patternProperties, etc.) become JSON objects rather than empty
            // arrays. PHP's json_encode emits [] for an empty array, but
            // JSON Schema requires these fields to be objects — swaggest
            // rejects `{"properties": []}` as a schema error, which would
            // surface as a validation failure on otherwise-innocent inputs.
            $schemaObj = json_decode((string) json_encode(
                $this->normalizeObjectFields($this->jsonSchema),
                JSON_UNESCAPED_SLASHES,
            ));
            // Tool input is semantically a JSON object (a map of param name
            // to value). An empty [] in PHP encodes to [] (array), which
            // fails `type: object`. Coerce the top level to an object so
            // the common "no parameters" case validates against object schemas.
            $dataObj = $input === []
                ? new \stdClass()
                : json_decode((string) json_encode($input, JSON_UNESCAPED_SLASHES));
            $remoteRefProvider = new \HaoCode\Support\JsonSchema\DenyRemoteRefProvider;
            $schemaContext = new \Swaggest\JsonSchema\Context($remoteRefProvider);
            $validationContext = new \Swaggest\JsonSchema\Context($remoteRefProvider);
            \Swaggest\JsonSchema\Schema::import($schemaObj, $schemaContext)
                ->in($dataObj, $validationContext);

            return [];
        } catch (\HaoCode\Support\JsonSchema\ExternalReferenceException) {
            return [
                'External JSON Schema references are not supported; '
                .'only local fragment references beginning with "#" are allowed.',
            ];
        } catch (\Swaggest\JsonSchema\InvalidValue $e) {
            // Data violated the schema — surface as a validation error.
            return [trim($e->getMessage())];
        } catch (\Throwable $e) {
            // Schema itself was malformed (unsupported draft, recursive $ref,
            // non-standard MCP schema). Degrade to allow so one bad schema
            // doesn't break every call to this tool. Production logging is
            // the caller's responsibility; here we silently allow.
            return [];
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

    /**
     * @param string[] $rules
     */
    private function validateValue(string $attribute, bool $exists, mixed $value, array $rules): ?string
    {
        $nullable = in_array('nullable', $rules, true);

        foreach ($rules as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '' || $rule === 'nullable') {
                continue;
            }

            if ($rule === 'present') {
                if (! $exists) {
                    return "The {$attribute} field must be present.";
                }
                continue;
            }

            if ($rule === 'required') {
                if (! $exists || $value === null || $value === '' || $value === []) {
                    return "The {$attribute} field is required.";
                }
                continue;
            }

            if (! $exists || ($value === null && $nullable)) {
                continue;
            }

            if ($rule === 'string' && ! is_string($value)) {
                return "The {$attribute} field must be a string.";
            }

            if ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                return "The {$attribute} field must be an integer.";
            }

            if ($rule === 'numeric' && ! is_numeric($value)) {
                return "The {$attribute} field must be numeric.";
            }

            if ($rule === 'boolean' && ! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                return "The {$attribute} field must be true or false.";
            }

            if ($rule === 'array' && ! is_array($value)) {
                return "The {$attribute} field must be an array.";
            }

            if ($rule === 'url' && (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false)) {
                return "The {$attribute} field must be a valid URL.";
            }

            if (str_starts_with($rule, 'in:')) {
                $allowed = explode(',', substr($rule, 3));
                if (! in_array((string) $value, $allowed, true)) {
                    return "The {$attribute} field must be one of: ".implode(', ', $allowed).'.';
                }
            }

            if (str_starts_with($rule, 'min:')) {
                $min = (float) substr($rule, 4);
                if ($this->measure($value) < $min) {
                    return "The {$attribute} field must be at least {$min}.";
                }
            }

            if (str_starts_with($rule, 'max:')) {
                $max = (float) substr($rule, 4);
                if ($this->measure($value) > $max) {
                    return "The {$attribute} field must not be greater than {$max}.";
                }
            }

            if (str_starts_with($rule, 'regex:')) {
                $pattern = substr($rule, 6);
                if (! is_string($value) || @preg_match($pattern, $value) !== 1) {
                    return "The {$attribute} field format is invalid.";
                }
            }
        }

        return null;
    }

    private function measure(mixed $value): float
    {
        if (is_array($value)) {
            return (float) count($value);
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) mb_strlen((string) $value);
    }

    /**
     * @return array<int, array{0: string, 1: bool, 2: mixed}>
     */
    private function valuesForAttribute(array $input, string $attribute): array
    {
        return $this->walkAttribute($input, explode('.', $attribute), $attribute);
    }

    /**
     * @param string[] $segments
     * @return array<int, array{0: string, 1: bool, 2: mixed}>
     */
    private function walkAttribute(mixed $value, array $segments, string $label): array
    {
        if ($segments === []) {
            return [[$label, true, $value]];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            if (! is_array($value)) {
                return [];
            }

            $matches = [];
            foreach ($value as $index => $item) {
                array_push($matches, ...$this->walkAttribute($item, $segments, str_replace('*', (string) $index, $label)));
            }

            return $matches;
        }

        if (! is_array($value) || ! array_key_exists((string) $segment, $value)) {
            return [[$label, false, null]];
        }

        return $this->walkAttribute($value[$segment], $segments, $label);
    }
}

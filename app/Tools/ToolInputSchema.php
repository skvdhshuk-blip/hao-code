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
     */
    public function validate(array $input): array
    {
        if (empty($this->validationRules)) {
            return $input;
        }

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

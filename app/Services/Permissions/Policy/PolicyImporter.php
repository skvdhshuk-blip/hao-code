<?php

namespace HaoCode\Services\Permissions\Policy;

use RuntimeException;

class PolicyImporter
{
    private PolicyLoader $loader;

    public function __construct(?PolicyLoader $loader = null)
    {
        $this->loader = $loader ?? new PolicyLoader;
    }

    /**
     * Import policy rules from a JSON string (as produced by policy:dump).
     *
     * @return PolicyRule[]
     */
    public function importJson(string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON: '.json_last_error_msg());
        }

        if (! isset($data['rules']) || ! is_array($data['rules'])) {
            throw new RuntimeException("JSON must contain a top-level 'rules' array.");
        }

        $rules = [];
        foreach ($data['rules'] as $i => $raw) {
            if (! is_array($raw)) {
                throw new RuntimeException("Rule at index {$i} is not an object.");
            }
            $this->validateRaw($raw, $i);
            $rules[] = PolicyRule::fromArray($raw);
        }

        // Run all PolicyLoader validations (ReDoS, conflicts, high-risk auto-approve, env_deny defaults, cwd)
        $this->loader->validateAll($rules);

        return $rules;
    }

    /**
     * Import from a JSON file path.
     *
     * @return PolicyRule[]
     */
    public function importFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Policy JSON file not found: {$filePath}");
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new RuntimeException("Could not read file: {$filePath}");
        }

        return $this->importJson($json);
    }

    private function validateRaw(array $raw, int $index): void
    {
        foreach (['name', 'tool', 'cmd'] as $required) {
            if (empty($raw[$required])) {
                throw new RuntimeException(
                    "Rule at index {$index} is missing required field '{$required}'."
                );
            }
        }
    }
}

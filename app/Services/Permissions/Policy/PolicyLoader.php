<?php

namespace HaoCode\Services\Permissions\Policy;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class PolicyLoader
{
    /** Env vars that must always be in env_deny (cannot be removed) */
    public const REQUIRED_ENV_DENY = [
        'LD_PRELOAD',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_LIBRARY_PATH',
        'PYTHONPATH',
        'NODE_OPTIONS',
        'PERL5OPT',
    ];

    private const MAX_REGEX_LENGTH = 1000;

    /** @return PolicyRule[] */
    public function load(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Policy file not found: {$filePath}");
        }

        $data = Yaml::parseFile($filePath);
        $rawRules = $data['rules'] ?? [];

        $rules = [];
        foreach ($rawRules as $raw) {
            $rules[] = PolicyRule::fromArray($raw);
        }

        $this->validateAll($rules);

        return $rules;
    }

    /** @param PolicyRule[] $rules */
    public function validateAll(array $rules): void
    {
        $this->validateRegexLengths($rules);
        $this->validateNoConflicts($rules);
        $this->validateNoHighRiskAutoApprove($rules);
        $this->validateEnvDenyDefaults($rules);
        $this->validateCwdRestrictions($rules);
    }

    /** Validation 1: regex args_match length ≤ 1000 (ReDoS prevention) */
    private function validateRegexLengths(array $rules): void
    {
        foreach ($rules as $rule) {
            foreach ($rule->argsMatch as $pattern) {
                if (strlen($pattern) > self::MAX_REGEX_LENGTH) {
                    throw new RuntimeException(
                        "Rule '{$rule->name}': args_match pattern exceeds ".self::MAX_REGEX_LENGTH.' chars (ReDoS risk).'
                    );
                }
            }
        }
    }

    /** Validation 2: no conflicting rules for same tool+cmd+args signature */
    private function validateNoConflicts(array $rules): void
    {
        $seen = [];
        foreach ($rules as $rule) {
            // Include the args_match signature in the conflict key so that
            // rules targeting the same command but different argument shapes
            // (e.g. `git status` vs `git push --force`) can coexist with
            // different risk/allow_auto classifications.
            $key = $rule->tool.'::'.$rule->cmd.'::'.$this->argsSignature($rule->argsMatch);
            if (isset($seen[$key])) {
                $existing = $seen[$key];
                if ($existing->allowChain !== $rule->allowChain
                    || $existing->risk !== $rule->risk
                    || $existing->allowAuto !== $rule->allowAuto
                ) {
                    throw new RuntimeException(
                        "Conflicting rules for tool='{$rule->tool}' cmd='{$rule->cmd}' args='".implode(',', $rule->argsMatch)."': '{$existing->name}' vs '{$rule->name}'."
                    );
                }
            }
            $seen[$key] = $rule;
        }
    }

    /**
     * Stable signature for an args_match list so that two rules with the same
     * command but different argument patterns are not treated as duplicates.
     * Order-independent: ["a","b"] and ["b","a"] collapse to the same key.
     */
    private function argsSignature(array $argsMatch): string
    {
        if ($argsMatch === []) {
            return '~';
        }
        $copy = $argsMatch;
        sort($copy);

        return implode('|', $copy);
    }

    /** Validation 3: risk=high + allow_auto=true is forbidden */
    private function validateNoHighRiskAutoApprove(array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule->isHighRisk() && $rule->allowAuto) {
                throw new RuntimeException(
                    "Rule '{$rule->name}': risk=high combined with allow_auto=true is forbidden."
                );
            }
        }
    }

    /** Validation 4: env_deny must contain all default required entries */
    private function validateEnvDenyDefaults(array $rules): void
    {
        foreach ($rules as $rule) {
            if (empty($rule->envDeny)) {
                continue;
            }
            $missing = array_diff(self::REQUIRED_ENV_DENY, $rule->envDeny);
            if (! empty($missing)) {
                throw new RuntimeException(
                    "Rule '{$rule->name}': env_deny is missing required entries: ".implode(', ', $missing).'.'
                );
            }
        }
    }

    /** Validation 5: cwd_restriction paths must be valid (realpath normalization) */
    private function validateCwdRestrictions(array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule->cwdRestriction === null) {
                continue;
            }

            $path = $rule->cwdRestriction;

            // Must be absolute path
            if (! str_starts_with($path, '/')) {
                throw new RuntimeException(
                    "Rule '{$rule->name}': cwd_restriction '{$path}' must be an absolute path."
                );
            }

            // Normalize by resolving . and .. without requiring path to exist on disk
            $normalized = $this->normalizePath($path);
            if ($normalized === false || $normalized === '') {
                throw new RuntimeException(
                    "Rule '{$rule->name}': cwd_restriction '{$path}' is invalid."
                );
            }

            // Prevent traversal outside root
            if (! str_starts_with($normalized, '/')) {
                throw new RuntimeException(
                    "Rule '{$rule->name}': cwd_restriction '{$path}' resolves outside filesystem root."
                );
            }
        }
    }

    private function normalizePath(string $path): string|false
    {
        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/'.implode('/', $resolved);
    }
}

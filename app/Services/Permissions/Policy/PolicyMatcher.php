<?php

namespace HaoCode\Services\Permissions\Policy;

use OpenTelemetry\API\Globals;

class PolicyMatcher
{
    private const STDIN_SIZE_LIMIT = 1048576; // 1 MiB

    /** Shell chain operators that are forbidden by default */
    private const CHAIN_PATTERN = '/(?:&&|\|\|?|;;?|\$\(|`)/';

    /** @param PolicyRule[] $rules */
    public function __construct(
        private readonly array $rules,
    ) {}

    /**
     * Returns a matcher that allows every Bash command — use only when no policy
     * enforcement is required and the caller must satisfy a non-nullable constraint.
     */
    public static function allowAll(): self
    {
        return new self([
            PolicyRule::fromArray(['name' => 'allow-all', 'tool' => 'Bash', 'cmd' => '*']),
        ]);
    }

    /**
     * Match tool+cmd against loaded rules and return a decision.
     *
     * @param  array<string, mixed>  $context  Keys: args (string), env (array), cwd (string), stdin_size (int)
     */
    public function match(string $tool, string $cmd, array $context = []): PolicyDecision
    {
        $args = $context['args'] ?? '';
        $env = $context['env'] ?? [];
        $cwd = $context['cwd'] ?? null;
        $stdinSize = $context['stdin_size'] ?? 0;

        // stdin size limit check (always applied)
        if ($stdinSize > self::STDIN_SIZE_LIMIT) {
            return PolicyDecision::deny(
                'stdin exceeds 1 MiB limit ('.$stdinSize.' bytes).',
            );
        }

        foreach ($this->rules as $rule) {
            if ($rule->tool !== $tool) {
                continue;
            }

            // Command chain precheck happens before cmd matching
            // so chain operators in the full cmd string are caught first.
            $chainDecision = $this->checkChain($cmd, $rule);
            if ($chainDecision !== null) {
                return $chainDecision;
            }

            if (! $this->ruleMatches($rule, $tool, $cmd, $args)) {
                continue;
            }

            // env_deny check
            $envDecision = $this->checkEnvDeny($env, $rule);
            if ($envDecision !== null) {
                return $envDecision;
            }

            // cwd_restriction check
            $cwdDecision = $this->checkCwd($cwd, $rule);
            if ($cwdDecision !== null) {
                return $cwdDecision;
            }

            // risk=high → approval required (three-piece)
            if ($rule->isHighRisk()) {
                return $this->buildHighRiskDecision($rule);
            }

            return PolicyDecision::allow($rule->name);
        }

        // No rule matched → deny by default (fail-closed)
        return PolicyDecision::deny('No matching policy rule found for '.$tool.':'.$cmd.'.');
    }

    private function ruleMatches(PolicyRule $rule, string $tool, string $cmd, string $args): bool
    {
        if (! $this->matchCmd($rule->cmd, $cmd)) {
            return false;
        }

        foreach ($rule->argsMatch as $pattern) {
            if (! $this->matchArg($pattern, $args)) {
                return false;
            }
        }

        return true;
    }

    private function matchCmd(string $ruleCmd, string $cmd): bool
    {
        if ($ruleCmd === '*') {
            return true;
        }
        if ($ruleCmd === $cmd) {
            return true;
        }
        // When chain operators are present, also match the first token of the command
        $firstToken = preg_split('/[\s&&|;]/', trim($cmd), 2)[0] ?? $cmd;

        return $ruleCmd === $firstToken;
    }

    private function matchArg(string $pattern, string $args): bool
    {
        // Regex mode: enclosed in /
        if (str_starts_with($pattern, '/') && preg_match('/^\/.*\/[a-z]*$/', $pattern)) {
            return (bool) @preg_match($pattern, $args);
        }

        // Wildcard mode: contains *
        if (str_contains($pattern, '*')) {
            return fnmatch($pattern, $args);
        }

        // Exact mode
        return $pattern === $args;
    }

    private function checkChain(string $cmd, PolicyRule $rule): ?PolicyDecision
    {
        if (! $rule->allowChain && preg_match(self::CHAIN_PATTERN, $cmd)) {
            return PolicyDecision::deny(
                "Command chain operators (&&, |, ;, \$(), \`) are forbidden for rule '{$rule->name}'.",
                $rule->name,
            );
        }

        return null;
    }

    private function checkEnvDeny(array $env, PolicyRule $rule): ?PolicyDecision
    {
        if (empty($rule->envDeny)) {
            // Even with no rule-level env_deny, apply the global required denies
            $globalDeny = PolicyLoader::REQUIRED_ENV_DENY;
            foreach ($globalDeny as $key) {
                if (array_key_exists($key, $env)) {
                    return PolicyDecision::deny(
                        "Environment variable '{$key}' is in the default deny list.",
                        $rule->name,
                    );
                }
            }

            return null;
        }

        foreach ($rule->envDeny as $key) {
            if (array_key_exists($key, $env)) {
                return PolicyDecision::deny(
                    "Environment variable '{$key}' is denied by rule '{$rule->name}'.",
                    $rule->name,
                );
            }
        }

        return null;
    }

    private function checkCwd(?string $cwd, PolicyRule $rule): ?PolicyDecision
    {
        if ($rule->cwdRestriction === null || $cwd === null) {
            return null;
        }

        $normalizedCwd = realpath($cwd) ?: $cwd;
        $normalizedRestriction = rtrim($rule->cwdRestriction, '/');

        if (! str_starts_with($normalizedCwd, $normalizedRestriction.'/') && $normalizedCwd !== $normalizedRestriction) {
            return PolicyDecision::deny(
                "Working directory '{$cwd}' is outside restriction '{$rule->cwdRestriction}' for rule '{$rule->name}'.",
                $rule->name,
            );
        }

        return null;
    }

    private function buildHighRiskDecision(PolicyRule $rule): PolicyDecision
    {
        // Emit OTEL span for high-risk decision
        $this->emitHighRiskSpan($rule);

        // risk=high: no cache, requires approval with rule name for re-confirmation
        return PolicyDecision::approvalRequired(
            $rule->name,
            "High-risk rule '{$rule->name}' requires explicit approval.",
            false, // no cache
        );
    }

    private function emitHighRiskSpan(PolicyRule $rule): void
    {
        try {
            $tracer = Globals::tracerProvider()->getTracer('haocode.permissions');
            $span = $tracer->spanBuilder('permission.high_risk_decision')
                ->startSpan();
            $span->setAttribute('rule.name', $rule->name);
            $span->setAttribute('rule.tool', $rule->tool);
            $span->setAttribute('rule.cmd', $rule->cmd);
            $span->setAttribute('rule.risk', $rule->risk);
            $span->end();
        } catch (\Throwable) {
            // OTEL is best-effort — never block on telemetry failure
        }
    }
}

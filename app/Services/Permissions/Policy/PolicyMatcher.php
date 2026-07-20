<?php

namespace HaoCode\Services\Permissions\Policy;

use OpenTelemetry\API\Globals;

class PolicyMatcher
{
    private const STDIN_SIZE_LIMIT = 1048576; // 1 MiB

    /** Shell chain operators that are forbidden by default */
    private const CHAIN_PATTERN = '/(?:&&|\|\|?|;;?|\$\(|`)/';

    /** @var PolicyRule[] sorted by specificity */
    private readonly array $rules;

    /** @param PolicyRule[] $rules */
    public function __construct(array $rules)
    {
        // Sort once by specificity so that more specific rules win over
        // wildcard fallbacks regardless of YAML declaration order. This is
        // critical when a policy bundles both a `cmd: "*"` catch-all and
        // targeted rules for the same tool — without this, the catch-all
        // shadows everything declared after it.
        //
        // Specificity tuple (higher wins):
        //   1. cmd is not the "*" wildcard
        //   2. rule has args_match patterns
        // PHP's usort is stable since 8.0, so equal-specificity rules keep
        // their original declaration order.
        usort($rules, function (PolicyRule $a, PolicyRule $b): int {
            $aSpecificity = $this->specificity($a);
            $bSpecificity = $this->specificity($b);
            $byWildcard = $bSpecificity[0] <=> $aSpecificity[0];   // specific cmd first
            if ($byWildcard !== 0) {
                return $byWildcard;
            }

            return $bSpecificity[1] <=> $aSpecificity[1];         // has args first
        });

        $this->rules = $rules;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function specificity(PolicyRule $rule): array
    {
        return [
            $rule->cmd === '*' ? 0 : 1,
            $rule->argsMatch === [] ? 0 : 1,
        ];
    }

    /**
     * @return PolicyRule[] sorted by specificity
     */
    public function getRules(): array
    {
        return $this->rules;
    }

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
     * @param  array<string, mixed>  $context  Keys: args (string), env (array), cwd (string), stdin_size (int), raw_command (string)
     */
    public function match(string $tool, string $cmd, array $context = []): PolicyDecision
    {
        $args = $context['args'] ?? '';
        $env = $context['env'] ?? [];
        $cwd = $context['cwd'] ?? null;
        $stdinSize = $context['stdin_size'] ?? 0;
        // The full command string (binary + args) is what chain-operator
        // detection must scan. Callers that pre-split the command into
        // binary + args (e.g. PermissionChecker) MUST forward the original
        // string here, otherwise `allow_chain: false` is bypassable by hiding
        // operators in the args (e.g. `composer install && curl ...`).
        $rawCommand = $context['raw_command'] ?? null;

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
            // so chain operators in the full command string are caught first.
            // Prefer the unsplit raw_command when available; fall back to $cmd
            // for direct callers (tests, JobStore) that pass the full string in.
            $chainDecision = $this->checkChain($rawCommand ?? $cmd, $rule);
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

            // allow_auto: true → short-circuit to AllowAuto so the
            // PermissionChecker can bypass the human-approval flow. Plain
            // allowAuto=false still returns Allow and falls through to the
            // deny/allow/dangerous-pattern pipeline.
            return $rule->allowAuto
                ? PolicyDecision::allowAuto($rule->name)
                : PolicyDecision::allow($rule->name);
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

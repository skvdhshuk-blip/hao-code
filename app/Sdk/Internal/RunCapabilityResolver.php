<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Api\Capability\CapabilityStatus;
use HaoCode\Services\Api\Capability\ProviderCapabilityRegistry;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\ResolvedProviderConfig;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Sdk\Sandbox\SandboxToolPolicy;

/**
 * Adapts SDK configuration into a run-scoped effective capability manifest.
 *
 * @internal
 */
final class RunCapabilityResolver
{
    public function __construct(
        private readonly ProviderCapabilityRegistry $providers,
    ) {}

    public static function defaults(): self
    {
        return new self(ProviderCapabilityRegistry::defaults());
    }

    public function resolve(
        HaoCodeConfig $config,
        ResolvedProviderConfig $resolvedProvider,
        ?array $effectiveToolNames = null,
        ?PermissionMode $permissionMode = null,
        ?array $runtimeAgentCapabilities = null,
    ): EffectiveCapabilityManifest {
        $provider = $this->providers->resolve($resolvedProvider);
        $tools = $this->resolveTools($config, $effectiveToolNames);
        $agent = [
            ProviderCapabilityRegistry::TEXT => true,
            ProviderCapabilityRegistry::STREAMING => true,
            ProviderCapabilityRegistry::TOOLS => $tools['uses_tools'],
            ProviderCapabilityRegistry::MULTI_TURN_TOOLS => $tools['uses_tools'],
            ProviderCapabilityRegistry::STRUCTURED_OUTPUT => $config->responseSchema !== null,
            ProviderCapabilityRegistry::THINKING => $config->thinkingEnabled,
            ProviderCapabilityRegistry::IMAGES => $config->images !== [],
            ProviderCapabilityRegistry::ABORT => $config->abortController !== null,
            ProviderCapabilityRegistry::OAUTH_BEARER => $config->oauthBearer === true,
            ProviderCapabilityRegistry::CUSTOM_HEADERS => $config->headers !== [],
        ];
        if ($runtimeAgentCapabilities !== null) {
            foreach ($runtimeAgentCapabilities as $capability => $requested) {
                if (array_key_exists($capability, $agent) && is_bool($requested)) {
                    $agent[$capability] = $requested;
                }
            }
        }

        $violations = [];
        foreach ($agent as $capability => $requested) {
            if (! $requested || $provider->status($capability) !== CapabilityStatus::Unsupported) {
                continue;
            }

            $decision = $provider->decision($capability);
            $detail = $decision['reason'] ?? 'The selected provider does not support this capability.';
            $violations[] = sprintf(
                '%s is unsupported for provider %s, model %s, endpoint %s (%s).',
                $capability,
                $resolvedProvider->providerType,
                $resolvedProvider->model,
                $provider->displayEndpoint(),
                $detail,
            );
        }

        $sandbox = $this->resolveSandbox($config, $violations);
        $permissionMode ??= PermissionMode::from($config->permissionMode);
        $permission = [
            'mode' => $permissionMode->value,
            'write_tools_enabled' => $permissionMode !== PermissionMode::Plan,
            'approval_bypassed' => $permissionMode === PermissionMode::BypassPermissions,
            'edits_preapproved' => in_array(
                $permissionMode,
                [PermissionMode::AcceptEdits, PermissionMode::BypassPermissions],
                true,
            ),
        ];

        foreach ($tools['sandbox_conflicts'] as $conflict) {
            $violations[] = $conflict;
        }

        return new EffectiveCapabilityManifest(
            provider: $provider,
            agent: $agent,
            tools: $tools,
            sandbox: $sandbox,
            permission: $permission,
            violations: array_values(array_unique($violations)),
        );
    }

    /**
     * @param list<string>|null $effectiveToolNames
     * @return array<string, mixed>
     */
    private function resolveTools(HaoCodeConfig $config, ?array $effectiveToolNames): array
    {
        $allowed = $this->normalizeNames($config->allowedTools);
        $disallowed = $this->normalizeNames($config->disallowedTools);
        $denied = array_fill_keys($disallowed, true);
        $wildcard = in_array('*', $allowed, true);
        $filter = $config->toolFilter();

        $custom = [];
        $sandboxConflicts = [];
        foreach ($config->tools as $tool) {
            if (! is_object($tool) || ! method_exists($tool, 'name')) {
                continue;
            }
            $name = trim((string) $tool->name());
            if ($name === '') {
                continue;
            }
            $custom[$name] = $filter === null || $filter($name);
            if ($config->sandbox !== null
                && in_array($name, SandboxRuntime::RESERVED_TOOL_NAMES, true)) {
                $sandboxConflicts[] = "Custom tool {$name} conflicts with a sandbox replacement tool.";
            }
        }

        $effective = [];
        foreach ($allowed as $name) {
            if ($name === '*' || isset($denied[$name])) {
                continue;
            }
            if ($filter === null || $filter($name)) {
                $effective[$name] = true;
            }
        }
        foreach ($custom as $name => $enabled) {
            if ($enabled) {
                $effective[$name] = true;
            }
        }

        if ($config->sandbox !== null) {
            foreach (SandboxToolPolicy::hostOnlyToolNames() as $name) {
                if (in_array($name, $allowed, true) && ! isset($denied[$name])) {
                    $sandboxConflicts[] = "Tool {$name} is host-only and cannot run while sandbox mode is active.";
                }
            }
            if (in_array('Bash', $allowed, true)
                && ! isset($denied['Bash'])
                && ! $config->sandbox->enablesBash()) {
                $sandboxConflicts[] = "Tool Bash requires sandbox mode \"full\"; current mode is \"{$config->sandbox->mode}\".";
            }
        }

        $effectiveNames = array_keys($effective);
        sort($effectiveNames);
        $actualNames = $effectiveToolNames === null
            ? null
            : $this->normalizeNames($effectiveToolNames);
        ksort($custom);

        return [
            'selection' => $wildcard ? 'wildcard' : ($allowed === [] ? 'none' : 'explicit'),
            'allowed' => $allowed,
            'disallowed' => $disallowed,
            'effective_requested' => $effectiveNames,
            'effective' => $actualNames,
            'custom' => $custom,
            'uses_tools' => $actualNames === null
                ? ($wildcard || $effectiveNames !== [] || $config->enableAskUser)
                : $actualNames !== [],
            'sandbox_conflicts' => array_values(array_unique($sandboxConflicts)),
        ];
    }

    /**
     * @param list<string> $violations
     * @return array<string, mixed>
     */
    private function resolveSandbox(HaoCodeConfig $config, array &$violations): array
    {
        $sandbox = $config->sandbox;
        if ($sandbox === null) {
            return [
                'active' => false,
                'provider' => 'host',
                'mode' => null,
                'bash_enabled' => true,
            ];
        }

        if (! SandboxManager::supportsProvider($sandbox->provider)) {
            $violations[] = "Sandbox provider \"{$sandbox->provider}\" is unsupported.";
        }
        if (! SandboxManager::supportsMode($sandbox->mode)) {
            $violations[] = "Sandbox mode \"{$sandbox->mode}\" is unsupported; expected filesystem or full.";
        }

        return [
            'active' => true,
            'provider' => $sandbox->provider,
            'mode' => $sandbox->mode,
            'bash_enabled' => $sandbox->enablesBash(),
            'remote_cwd' => $sandbox->remoteCwd,
            'sync' => $sandbox->sync,
            'network' => is_string($sandbox->options['network'] ?? null)
                ? $sandbox->options['network']
                : null,
        ];
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function normalizeNames(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $names[trim($value)] = true;
        }

        $normalized = array_keys($names);
        sort($normalized);

        return $normalized;
    }
}

<?php

namespace HaoCode\Tools\Config;

use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class ConfigTool extends BaseTool
{
    public function __construct(
        private readonly ?SettingsManager $settings = null,
    ) {}

    public function name(): string
    {
        return 'Config';
    }

    public function description(): string
    {
        return <<<DESC
Get or set runtime configuration values. Supported keys: model, active_provider, api_base_url, max_tokens, permission_mode, output_style.

Usage:
- To get all settings: call with no arguments
- To get a specific key: call with key only
- To set a value: call with key and value

This tool takes effect immediately for the current session.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'The config key to get or set',
                    'enum' => ['model', 'active_provider', 'api_base_url', 'max_tokens', 'permission_mode', 'output_style'],
                ],
                'value' => [
                    'type' => ['string', 'null'],
                    'description' => 'The value to set (omit to get current value)',
                ],
            ],
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        /** @var SettingsManager $settings */
        $settings = $this->settings ?? new SettingsManager($context->workingDirectory);
        $key = $input['key'] ?? null;
        $value = $input['value'] ?? null;

        // Get all settings
        if ($key === null) {
            $all = $settings->all();
            $lines = [];
            foreach ($all as $k => $v) {
                $lines[] = "  {$k}: ".$this->displayValue($v);
            }
            return ToolResult::success("Current settings:\n" . implode("\n", $lines));
        }

        // Get specific key
        if ($value === null) {
            $all = $settings->all();
            $current = array_key_exists($key, $all) ? $all[$key] : 'unknown';
            return ToolResult::success("{$key} = ".$this->displayValue($current));
        }

        if ($key === 'active_provider') {
            $normalizedValue = is_string($value) && in_array(strtolower(trim($value)), ['off', 'none', 'clear'], true)
                ? null
                : trim((string) $value);

            if ($normalizedValue !== null) {
                $providers = array_keys($settings->getConfiguredProviders());
                if ($providers === []) {
                    return ToolResult::error('No providers are configured. Add a "provider" object to your settings.json first.');
                }

                if (! in_array($normalizedValue, $providers, true)) {
                    return ToolResult::error('Unknown provider: '.$normalizedValue.'. Available: '.implode(', ', $providers));
                }
            }

            try {
                $settings->set('active_provider', $normalizedValue);
            } catch (\RuntimeException|\InvalidArgumentException $exception) {
                return ToolResult::error($exception->getMessage());
            }

            return ToolResult::success('Set active_provider = '.$this->displayValue($normalizedValue));
        }

        // Validate and set
        $error = $this->validateValue($key, $value);
        if ($error === null && $key === 'api_base_url') {
            $error = $this->validateRuntimeBaseUrl($value, $settings->getBaseUrl());
        }
        if ($error !== null) {
            return ToolResult::error($error);
        }

        try {
            $settings->set(
                $key,
                $key === 'output_style' && in_array(strtolower($value), ['off', 'none'], true) ? null : $value,
            );
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        return ToolResult::success("Set {$key} = ".$this->displayValue(
            $key === 'output_style' && in_array(strtolower($value), ['off', 'none'], true) ? null : $value,
        ));
    }

    private function validateValue(string $key, string $value): ?string
    {
        return match ($key) {
            'model' => null, // Accept any model string
            'active_provider' => null,
            'api_base_url' => $this->isHttpUrl($value)
                ? null
                : 'api_base_url must be an absolute HTTP(S) URL.',
            'max_tokens' => is_numeric($value) && (int) $value > 0 ? null : "max_tokens must be a positive integer",
            'permission_mode' => in_array($value, ['default', 'plan', 'accept_edits', 'bypass_permissions'])
                ? null
                : "Invalid permission mode. Must be: default, plan, accept_edits, or bypass_permissions",
            'output_style' => null,
            default => "Unknown config key: {$key}",
        };
    }

    private function validateRuntimeBaseUrl(string $value, string $current): ?string
    {
        $next = parse_url($value);
        $active = parse_url($current);
        if (! is_array($next) || ! is_array($active)) {
            return 'api_base_url must be an absolute HTTP(S) URL.';
        }
        if (isset($next['user']) || isset($next['pass']) || isset($next['query']) || isset($next['fragment'])) {
            return 'api_base_url cannot contain credentials, a query string, or a fragment.';
        }

        if ($this->origin($next) !== $this->origin($active)) {
            return 'api_base_url can only change the path on the active provider origin; select a configured active_provider to change hosts.';
        }

        return null;
    }

    private function isHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /** @param array<string, mixed> $parts */
    private function origin(array $parts): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));

        return $scheme.'://'.$host.':'.$port;
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return 'off';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unknown';
    }

    public function isReadOnly(array $input): bool
    {
        return !isset($input['value']);
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}

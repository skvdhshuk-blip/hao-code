<?php

declare(strict_types=1);

namespace HaoCode\Support\Runtime;

use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use HaoCode\Services\Settings\SettingsManager;

/**
 * Builds the environment array for every local subprocess (Bash, sandbox exec).
 *
 * Always strips the hard-coded injection denylist; optional extra keys can be
 * removed for policy-level env_deny.
 *
 * @internal
 */
final class SpawnEnvironment
{
    /**
     * @param  list<string>|null  $extraDeny
     * @return array<string, string>
     */
    public static function build(?array $extraDeny = null): array
    {
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }

        foreach (PolicyLoader::REQUIRED_ENV_DENY as $key) {
            unset($env[$key]);
        }
        if ($extraDeny !== null) {
            foreach ($extraDeny as $key) {
                if (is_string($key) && $key !== '') {
                    unset($env[$key]);
                }
            }
        }

        // Ensure string values only (proc_open env contract).
        $clean = [];
        foreach ($env as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value) || is_numeric($value)) {
                $clean[$key] = (string) $value;
            }
        }

        if (! isset($clean['TERM']) || $clean['TERM'] === '') {
            $clean['TERM'] = 'xterm-256color';
        }

        return $clean;
    }

    /**
     * Build the exact environment for a policy-governed command.
     *
     * @internal
     *
     * @return array<string, string>
     */
    public static function forCommand(
        ?SettingsManager $settings,
        string $tool,
        string $command,
        string $cwd,
    ): array {
        return self::build(self::deniedKeysForCommand($settings, $tool, $command, $cwd));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function deniedKeysForCommand(
        ?SettingsManager $settings,
        string $tool,
        string $command,
        string $cwd,
    ): array {
        $denied = PolicyLoader::REQUIRED_ENV_DENY;
        if ($settings === null) {
            return $denied;
        }

        $rules = [];
        $loader = new PolicyLoader;
        foreach ($settings->getPolicyFiles() as $file) {
            $rules = array_merge($rules, $loader->load($file));
        }
        if ($rules === []) {
            return $denied;
        }

        $parts = preg_split('/\s+/', trim($command), 2);
        $binary = $parts[0] ?? $command;
        $args = $parts[1] ?? '';
        $custom = (new PolicyMatcher($rules))->envDenyFor($tool, $binary, [
            'args' => $args,
            'cwd' => $cwd,
            'raw_command' => $command,
        ]);

        return array_values(array_unique(array_merge($denied, $custom)));
    }

    /**
     * Prefix a sandbox command with an environment scrub. Remote sandbox
     * backends do not inherit the PHP host env array, so the command boundary
     * must enforce the same denylist inside the sandbox shell.
     *
     * @internal
     */
    public static function scrubCommand(string $command, array $deniedKeys): string
    {
        $valid = array_values(array_filter(
            array_unique($deniedKeys),
            static fn (mixed $key): bool => is_string($key)
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1,
        ));
        if ($valid === []) {
            return $command;
        }

        return 'unset '.implode(' ', $valid).";\n".$command;
    }
}

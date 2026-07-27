<?php

declare(strict_types=1);

namespace HaoCode\Support\Runtime;

use HaoCode\Services\Permissions\Policy\PolicyLoader;

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
}

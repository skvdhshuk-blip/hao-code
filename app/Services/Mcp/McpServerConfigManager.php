<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Services\Settings\SettingsFileStore;

class McpServerConfigManager
{
    private readonly SettingsFileStore $fileStore;

    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {
        $this->fileStore = new SettingsFileStore($workingDirectory);
    }

    /**
     * @return array{global: string, project: string}
     */
    public function paths(): array
    {
        return $this->fileStore->paths();
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>,
     *     oauth: array<string, string>,
     *     cwd: string
     * }>
     */
    public function listServers(): array
    {
        $merged = [];

        foreach ($this->paths() as $scope => $path) {
            foreach ($this->readServersFromFile($path) as $name => $definition) {
                $merged[$name] = $this->normalizeServerDefinition($name, $definition, $scope, $path);
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @return array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>,
     *     oauth: array<string, string>,
     *     cwd: string
     * }|null
     */
    public function getServer(string $name): ?array
    {
        foreach ($this->listServers() as $server) {
            if ($server['name'] === $name) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function addServer(string $name, array $definition, string $scope = 'project'): void
    {
        $path = $this->pathForScope($scope);
        $this->fileStore->update($path, function (array &$settings) use ($name, $definition): void {
            if (! isset($settings['mcp_servers']) || ! is_array($settings['mcp_servers'])) {
                $settings['mcp_servers'] = [];
            }
            $settings['mcp_servers'][$name] = $definition;
        });
    }

    public function removeServer(string $name, string $scope = 'all'): int
    {
        $removed = 0;

        foreach ($this->targetScopes($scope) as $targetScope) {
            $path = $this->pathForScope($targetScope);
            if (! file_exists($path)) {
                continue;
            }
            $removed += (int) $this->fileStore->update(
                $path,
                function (array &$settings) use ($name): bool {
                    if (! isset($settings['mcp_servers'][$name])) {
                        return false;
                    }

                    unset($settings['mcp_servers'][$name]);

                    return true;
                },
            );
        }

        return $removed;
    }

    public function setEnabled(string $name, bool $enabled, string $scope = 'all'): int
    {
        $updated = 0;

        foreach ($this->targetScopes($scope) as $targetScope) {
            $path = $this->pathForScope($targetScope);
            if (! file_exists($path)) {
                continue;
            }
            $updated += (int) $this->fileStore->update(
                $path,
                function (array &$settings) use ($name, $enabled): int {
                    if (! isset($settings['mcp_servers']) || ! is_array($settings['mcp_servers'])) {
                        return 0;
                    }

                    $names = $name === 'all'
                        ? array_keys($settings['mcp_servers'])
                        : [$name];
                    $scopeUpdated = 0;
                    foreach ($names as $serverName) {
                        if (! isset($settings['mcp_servers'][$serverName])
                            || ! is_array($settings['mcp_servers'][$serverName])) {
                            continue;
                        }

                        $settings['mcp_servers'][$serverName]['enabled'] = $enabled;
                        $scopeUpdated++;
                    }

                    return $scopeUpdated;
                },
            );
        }

        return $updated;
    }

    private function pathForScope(string $scope): string
    {
        $paths = $this->paths();

        return $paths[$scope] ?? $paths['project'];
    }

    /**
     * @return array<int, string>
     */
    private function targetScopes(string $scope): array
    {
        if ($scope === 'global' || $scope === 'project') {
            return [$scope];
        }

        return ['global', 'project'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readServersFromFile(string $path): array
    {
        $settings = $this->readSettingsFile($path);
        $servers = $settings['mcp_servers'] ?? [];

        return is_array($servers) ? array_filter($servers, 'is_array') : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettingsFile(string $path): array
    {
        return $this->fileStore->read($path);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{
     *     name: string,
     *     scope: string,
     *     path: string,
     *     enabled: bool,
     *     transport: string,
     *     command: ?string,
     *     args: array<int, string>,
     *     url: ?string,
     *     env: array<string, string>,
     *     headers: array<string, string>,
     *     oauth: array<string, string>,
     *     cwd: string
     * }
     */
    private function normalizeServerDefinition(string $name, array $definition, string $scope, string $path): array
    {
        $transport = is_string($definition['transport'] ?? null)
            ? strtolower($definition['transport'])
            : $this->inferTransport($definition);

        return [
            'name' => $name,
            'scope' => $scope,
            'path' => $path,
            'enabled' => (bool) ($definition['enabled'] ?? true),
            'transport' => $transport,
            'command' => is_string($definition['command'] ?? null) ? $definition['command'] : null,
            'args' => array_values(array_filter($definition['args'] ?? [], 'is_string')),
            'url' => is_string($definition['url'] ?? null) ? $definition['url'] : null,
            'env' => $this->normalizeStringMap($definition['env'] ?? []),
            'headers' => $this->normalizeStringMap($definition['headers'] ?? []),
            'oauth' => $this->normalizeOAuth($definition['oauth'] ?? []),
            'cwd' => $this->workingDirectory ?? (getcwd() ?: '/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function normalizeOAuth(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowedKeys = [
            'token_endpoint',
            'client_id',
            'client_id_env',
            'client_secret_env',
            'access_token_env',
            'refresh_token_env',
            'scope',
            'token_endpoint_auth_method',
        ];
        $normalized = [];
        foreach ($allowedKeys as $key) {
            if (is_scalar($value[$key] ?? null) && (string) $value[$key] !== '') {
                $normalized[$key] = (string) $value[$key];
            }
        }

        if (isset($normalized['token_endpoint_auth_method'])
            && ! in_array($normalized['token_endpoint_auth_method'], ['client_secret_basic', 'client_secret_post'], true)) {
            unset($normalized['token_endpoint_auth_method']);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $mapValue) {
            if (is_scalar($mapValue)) {
                $normalized[(string) $key] = (string) $mapValue;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function inferTransport(array $definition): string
    {
        if (is_string($definition['url'] ?? null)) {
            $url = strtolower($definition['url']);

            if (str_ends_with($url, '/sse')) {
                return 'sse';
            }

            return 'http';
        }

        return 'stdio';
    }
}

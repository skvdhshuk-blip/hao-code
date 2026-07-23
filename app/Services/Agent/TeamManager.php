<?php

namespace HaoCode\Services\Agent;

use HaoCode\Support\StateIdentifier;

class TeamManager
{
    public function __construct(
        private readonly ?string $storagePath = null,
    ) {
        $this->ensureStoragePath();
    }

    /**
     * @param  array<int, array{role: string, agent_type?: string, prompt?: string, model?: string|null}>  $members
     * @return array<string, mixed>
     */
    public function create(string $name, array $members): array
    {
        $name = StateIdentifier::teamName($name);
        $memberIds = [];
        $normalizedMembers = [];
        foreach ($members as $member) {
            $role = (string) ($member['role'] ?? '');
            $agentId = self::memberAgentId($name, $role);
            if (isset($memberIds[$agentId])) {
                throw new \InvalidArgumentException('Team member roles collide after normalization.');
            }
            $memberIds[$agentId] = true;
            $normalizedMembers[] = [
                'role' => $role,
                'agent_id' => $agentId,
                'agent_type' => $member['agent_type'] ?? 'general-purpose',
                'model' => $member['model'] ?? null,
                'prompt' => $member['prompt'] ?? 'Work on the '.$role.' part of the team objective.',
            ];
        }

        $team = [
            'name' => $name,
            'members' => $normalizedMembers,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        return $this->withTeamLock($name, LOCK_EX, function () use ($name, $team): array {
            $path = $this->teamPath($name);
            if (is_file($path)) {
                throw new \InvalidArgumentException("Team '{$name}' already exists.");
            }
            $this->writeJsonAtomically($path, $team);

            return $team;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $name = StateIdentifier::teamName($name);

        return $this->withTeamLock($name, LOCK_SH, function () use ($name): ?array {
            return $this->readJson($this->teamPath($name));
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $teams = [];

        foreach (glob($this->storageRoot().'/*.team.json') ?: [] as $path) {
            $file = basename($path);
            $name = substr($file, 0, -strlen('.team.json'));
            try {
                $team = $this->get($name);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (is_array($team)) {
                $teams[] = $team;
            }
        }

        usort($teams, fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        return $teams;
    }

    public function delete(string $name): bool
    {
        $name = StateIdentifier::teamName($name);

        return $this->withTeamLock($name, LOCK_EX, function () use ($name): bool {
            $path = $this->teamPath($name);
            if (! is_file($path)) {
                return false;
            }

            return unlink($path);
        });
    }

    /**
     * Generate the deterministic agent ID for a team member.
     */
    public static function memberAgentId(string $teamName, string $role): string
    {
        $teamName = StateIdentifier::teamName($teamName);
        $sanitized = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($role)));
        $sanitized = trim((string) $sanitized, '-');
        if ($sanitized === '') {
            throw new \InvalidArgumentException('Invalid team member role.');
        }

        return StateIdentifier::backgroundAgentId($teamName.'_'.$sanitized);
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJsonAtomically(string $path, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $temporary = tempnam($this->storageRoot(), '.haocode-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary team state file.');
        }

        try {
            $written = file_put_contents($temporary, $json);
            if ($written !== strlen($json)) {
                throw new \RuntimeException("Unable to write team state: {$path}");
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException("Unable to replace team state: {$path}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function withTeamLock(string $name, int $operation, callable $callback): mixed
    {
        $handle = fopen($this->storageRoot()."/{$name}.lock", 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open team lock for {$name}");
        }

        try {
            if (! flock($handle, $operation)) {
                throw new \RuntimeException("Unable to lock team state for {$name}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function teamPath(string $name): string
    {
        $name = StateIdentifier::teamName($name);

        return $this->storageRoot()."/{$name}.team.json";
    }

    private function storageRoot(): string
    {
        return $this->storagePath ?? sys_get_temp_dir().'/haocode_teams';
    }

    private function ensureStoragePath(): void
    {
        if (! is_dir($this->storageRoot()) && ! mkdir($this->storageRoot(), 0755, true) && ! is_dir($this->storageRoot())) {
            throw new \RuntimeException("Unable to create team storage: {$this->storageRoot()}");
        }
    }
}

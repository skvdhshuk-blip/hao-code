<?php

namespace HaoCode\Services\Permissions\Policy;

class PolicyRule
{
    public function __construct(
        public readonly string $name,
        public readonly string $tool,
        public readonly string $cmd,
        /** @var string[] */
        public readonly array $argsMatch = [],
        /** @var string[] */
        public readonly array $envAllow = [],
        /** @var string[] */
        public readonly array $envDeny = [],
        public readonly string $risk = 'normal',
        public readonly bool $allowChain = false,
        public readonly int $approvalTtl = 0,
        public readonly ?string $cwdRestriction = null,
        public readonly ?string $note = null,
        public readonly bool $allowAuto = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            tool: $data['tool'],
            cmd: $data['cmd'],
            argsMatch: $data['args_match'] ?? [],
            envAllow: $data['env_allow'] ?? [],
            envDeny: $data['env_deny'] ?? [],
            risk: $data['risk'] ?? 'normal',
            allowChain: $data['allow_chain'] ?? false,
            approvalTtl: $data['approval_ttl'] ?? 0,
            cwdRestriction: $data['cwd_restriction'] ?? null,
            note: $data['note'] ?? null,
            allowAuto: $data['allow_auto'] ?? false,
        );
    }

    public function isHighRisk(): bool
    {
        return $this->risk === 'high';
    }
}

<?php

namespace HaoCode\Services\Permissions\Policy;

enum PolicyDecisionKind: string
{
    case Allow = 'allow';
    case AllowAuto = 'allow_auto';
    case Deny = 'deny';
    case ApprovalRequired = 'approval_required';
}

class PolicyDecision
{
    private function __construct(
        public readonly PolicyDecisionKind $kind,
        public readonly ?string $ruleName = null,
        public readonly ?string $reason = null,
        public readonly bool $cacheDecision = true,
    ) {}

    public static function allow(?string $ruleName = null): self
    {
        return new self(PolicyDecisionKind::Allow, $ruleName);
    }

    /**
     * Like {@see allow()}, but signals that the matched rule was explicitly
     * marked `allow_auto: true` and the caller (PermissionChecker) may bypass
     * the human-approval flow entirely. This is the only policy outcome that
     * short-circuits to PermissionDecision::allow() — plain Allow still falls
     * through to the rest of the permission pipeline.
     */
    public static function allowAuto(?string $ruleName = null): self
    {
        return new self(PolicyDecisionKind::AllowAuto, $ruleName);
    }

    public static function deny(string $reason, ?string $ruleName = null): self
    {
        return new self(PolicyDecisionKind::Deny, $ruleName, $reason);
    }

    public static function approvalRequired(string $ruleName, string $reason, bool $cacheDecision = true): self
    {
        return new self(PolicyDecisionKind::ApprovalRequired, $ruleName, $reason, $cacheDecision);
    }

    public function isAllowed(): bool
    {
        return $this->kind === PolicyDecisionKind::Allow
            || $this->kind === PolicyDecisionKind::AllowAuto;
    }

    public function isDenied(): bool
    {
        return $this->kind === PolicyDecisionKind::Deny;
    }

    public function requiresApproval(): bool
    {
        return $this->kind === PolicyDecisionKind::ApprovalRequired;
    }
}

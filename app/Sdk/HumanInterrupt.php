<?php

namespace HaoCode\Sdk;

/**
 * Serializable checkpoint describing actions paused for human input.
 *
 * @api
 */
final class HumanInterrupt
{
    /**
     * @api
     *
     * @param HumanActionRequest[] $actions
     */
    public function __construct(
        /** @api */
        public readonly string $id,
        /** @api */
        public readonly string $sessionId,
        /** @api */
        public readonly array $actions,
        /** @api */
        public readonly string $createdAt,
        /** @api */
        public readonly ?string $sourceAgentId = null,
        /** @api */
        public readonly ?string $sourceTeam = null,
    ) {}

    /** @api */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'actions' => array_map(
                static fn (HumanActionRequest $action): array => $action->toArray(),
                $this->actions,
            ),
            'created_at' => $this->createdAt,
            'source_agent_id' => $this->sourceAgentId,
            'source_team' => $this->sourceTeam,
        ];
    }

    /** @api */
    public static function fromArray(array $value): self
    {
        return new self(
            id: (string) ($value['id'] ?? ''),
            sessionId: (string) ($value['session_id'] ?? $value['sessionId'] ?? ''),
            actions: array_map(
                static fn (array $action): HumanActionRequest => HumanActionRequest::fromArray($action),
                array_values(array_filter($value['actions'] ?? [], 'is_array')),
            ),
            createdAt: (string) ($value['created_at'] ?? $value['createdAt'] ?? ''),
            sourceAgentId: isset($value['source_agent_id']) ? (string) $value['source_agent_id'] : null,
            sourceTeam: isset($value['source_team']) ? (string) $value['source_team'] : null,
        );
    }
}

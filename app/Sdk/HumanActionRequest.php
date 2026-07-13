<?php

namespace HaoCode\Sdk;

/**
 * A tool action waiting for a human decision.
 *
 * @api
 */
final class HumanActionRequest
{
    /** @api */
    public function __construct(
        /** @api */
        public readonly string $id,
        /** @api */
        public readonly string $toolName,
        /** @api */
        public readonly array $input,
        /** @api */
        public readonly string $description,
        /** @api */
        public readonly array $allowedDecisions = ['approve', 'edit', 'reject', 'respond'],
        /** @api */
        public readonly ?string $agentId = null,
    ) {}

    /** @api */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool_name' => $this->toolName,
            'input' => $this->input,
            'description' => $this->description,
            'allowed_decisions' => $this->allowedDecisions,
            'agent_id' => $this->agentId,
        ];
    }

    /** @api */
    public static function fromArray(array $value): self
    {
        return new self(
            id: (string) ($value['id'] ?? ''),
            toolName: (string) ($value['tool_name'] ?? $value['toolName'] ?? ''),
            input: is_array($value['input'] ?? null) ? $value['input'] : [],
            description: (string) ($value['description'] ?? ''),
            allowedDecisions: array_values(
                $value['allowed_decisions']
                ?? $value['allowedDecisions']
                ?? ['approve', 'edit', 'reject', 'respond'],
            ),
            agentId: isset($value['agent_id']) ? (string) $value['agent_id'] : (isset($value['agentId']) ? (string) $value['agentId'] : null),
        );
    }
}

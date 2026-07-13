<?php

namespace HaoCode\Sdk;

/**
 * A human decision for one interrupted tool action.
 *
 * @api
 */
final class HumanDecision
{
    private const TYPES = ['approve', 'edit', 'reject', 'respond'];

    /** @api */
    public function __construct(
        /** @api */
        public readonly string $actionId,
        /** @api */
        public readonly string $type,
        /** @api */
        public readonly ?array $editedInput = null,
        /** @api */
        public readonly ?string $message = null,
        /** @api */
        public readonly mixed $response = null,
    ) {
        if (! in_array($this->type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown human decision type: '.$this->type);
        }
        if ($this->type === 'edit' && $this->editedInput === null) {
            throw new \InvalidArgumentException('Edit decisions require edited input.');
        }
    }

    /** @api */
    public static function approve(string $actionId): self
    {
        return new self($actionId, 'approve');
    }

    /** @api */
    public static function edit(string $actionId, array $input): self
    {
        return new self($actionId, 'edit', editedInput: $input);
    }

    /** @api */
    public static function reject(string $actionId, ?string $message = null): self
    {
        return new self($actionId, 'reject', message: $message);
    }

    /** @api */
    public static function respond(string $actionId, mixed $response): self
    {
        return new self($actionId, 'respond', response: $response);
    }

    /** @api */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'type' => $this->type,
            'edited_input' => $this->editedInput,
            'message' => $this->message,
            'response' => $this->response,
        ];
    }

    /** @api */
    public static function fromArray(array $value): self
    {
        return new self(
            actionId: (string) ($value['action_id'] ?? $value['actionId'] ?? ''),
            type: (string) ($value['type'] ?? ''),
            editedInput: is_array($value['edited_input'] ?? null) ? $value['edited_input'] : (is_array($value['editedInput'] ?? null) ? $value['editedInput'] : null),
            message: isset($value['message']) ? (string) $value['message'] : null,
            response: $value['response'] ?? null,
        );
    }
}

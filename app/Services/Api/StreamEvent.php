<?php

namespace HaoCode\Services\Api;

class StreamEvent
{
    public function __construct(
        public readonly string $type,
        public readonly ?array $data = null,
    ) {}

    public static function fromSse(string $eventType, string $rawData): self
    {
        return new self($eventType, json_decode($rawData, true));
    }

    /**
     * 判断该事件是否已经提交模型可见内容或可能触发工具副作用。
     */
    public function commitsResponseState(): bool
    {
        return ! in_array($this->type, ['message_start', 'ping'], true);
    }
}

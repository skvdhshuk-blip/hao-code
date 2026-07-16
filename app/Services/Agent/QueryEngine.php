<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\ToolRegistry;

class QueryEngine
{
    public function __construct(
        private readonly LlmProvider $streamingClient,
        private readonly ToolRegistry $toolRegistry,
        private readonly ?PhoenixTracer $tracer = null,
        private readonly ?SettingsManager $settings = null,
    ) {}

    /**
     * Execute a query against the configured LLM provider with streaming.
     */
    public function query(
        array $systemPrompt,
        array $messages,
        ?callable $onTextDelta = null,
        ?callable $onToolBlockComplete = null,
        ?callable $onThinkingDelta = null,
        ?callable $shouldAbort = null,
        ?array $toolsOverride = null,
    ): StreamProcessor {
        $tools = $toolsOverride ?? $this->toolRegistry->toApiTools();
        $processor = new StreamProcessor();

        if ($onToolBlockComplete) {
            $processor->setOnToolBlockComplete($onToolBlockComplete);
        }
        if ($onThinkingDelta) {
            $processor->setOnThinkingDelta($onThinkingDelta);
        }

        $llmSpan = $this->tracer?->startSpan(
            name: 'llm.chat',
            openInferenceKind: PhoenixTracer::KIND_LLM,
            attributes: $this->buildLlmSpanAttributes($systemPrompt, $messages, $tools),
        );
        $llmScope = $llmSpan?->activate();

        try {
            foreach ($this->streamingClient->streamMessages(
                systemPrompt: $systemPrompt,
                messages: $messages,
                tools: $tools,
                shouldAbort: $shouldAbort,
            ) as $event) {
                $processor->processEvent($event);

                if ($onTextDelta && $event->type === 'content_block_delta') {
                    $delta = $event->data['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                        $onTextDelta($delta['text']);
                    }
                }
            }

            if ($llmSpan !== null) {
                $this->annotateLlmSpanWithResult($llmSpan, $processor);
            }

            return $processor;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($llmSpan, $e);
            throw $e;
        } finally {
            $llmScope?->detach();
            $llmSpan?->end();
        }
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function buildLlmSpanAttributes(array $systemPrompt, array $messages, array $tools): array
    {
        $redact = $this->tracer?->shouldRedactMessages() ?? false;

        $attributes = [
            'llm.model_name' => $this->settings?->getModel() ?? 'unknown',
            'llm.provider' => $this->settings?->getProviderType() ?? 'anthropic',
            'llm.system' => $redact ? '[redacted]' : $this->flattenSystemPrompt($systemPrompt),
            'llm.input_messages_count' => count($messages),
            'llm.tools_count' => count($tools),
        ];

        foreach (array_slice($messages, -10) as $index => $message) {
            $role = (string) ($message['role'] ?? 'unknown');
            $content = $redact ? '[redacted]' : $this->flattenMessageContent($message['content'] ?? '');
            $attributes["llm.input_messages.{$index}.message.role"] = $role;
            $attributes["llm.input_messages.{$index}.message.content"] = $content;
        }

        if ($tools !== []) {
            $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $tools);
            $attributes['llm.tools.names'] = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
        }

        return $attributes;
    }

    /**
     * Pull token usage and output text from the finished StreamProcessor and
     * stick it on the LLM span.
     */
    private function annotateLlmSpanWithResult(\OpenTelemetry\API\Trace\SpanInterface $span, StreamProcessor $processor): void
    {
        // The response's model is authoritative — it reflects what the
        // provider actually served, including cases where SDK overrides
        // make the container SettingsManager's view of "current model"
        // stale or wrong (e.g. SDK path with bypassed settings).
        $responseModel = $processor->getModel();
        if ($responseModel !== null && $responseModel !== '') {
            $span->setAttribute('llm.model_name', $responseModel);
        }

        $usage = $processor->getUsage();
        $input = (int) ($usage['context_input_tokens'] ?? $usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $span->setAttribute('llm.token_count.prompt', $input);
        $span->setAttribute('llm.token_count.completion', $output);
        $span->setAttribute('llm.token_count.total', $input + $output);

        if ($processor->getStopReason() !== null) {
            $span->setAttribute('llm.stop_reason', $processor->getStopReason());
        }

        $span->setAttribute(
            'output.value',
            ($this->tracer?->shouldRedactMessages() ?? false)
                ? '[redacted]'
                : $processor->getAccumulatedText(),
        );

        $toolBlocks = $processor->getToolUseBlocks();
        if ($toolBlocks !== []) {
            $span->setAttribute('llm.output_tool_calls_count', count($toolBlocks));
            foreach (array_slice($toolBlocks, 0, 10) as $index => $block) {
                $span->setAttribute("llm.output_messages.{$index}.message.tool_calls.0.tool_call.function.name", (string) ($block['name'] ?? ''));
                $span->setAttribute(
                    "llm.output_messages.{$index}.message.tool_calls.0.tool_call.function.arguments",
                    json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                );
            }
        }
    }

    private function flattenSystemPrompt(array $systemPrompt): string
    {
        $parts = [];
        foreach ($systemPrompt as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return $this->truncate(implode("\n\n", $parts));
    }

    private function flattenMessageContent(mixed $content): string
    {
        if (is_string($content)) {
            return $this->truncate($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif ($type === 'tool_result') {
                $inner = $block['content'] ?? '';
                $parts[] = '[tool_result id='.($block['tool_use_id'] ?? '?').']' . (is_string($inner) ? ' ' . $inner : '');
            } elseif ($type === 'tool_use') {
                $parts[] = '[tool_use name='.($block['name'] ?? '?').']';
            } elseif ($type === 'image') {
                $parts[] = '[image]';
            }
        }

        return $this->truncate(implode("\n", $parts));
    }

    private function truncate(string $text, int $max = 8000): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '… (truncated)';
    }
}

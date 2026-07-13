<?php

namespace HaoCode\Tools\AskUserQuestion;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/** @internal */
final class AskUserQuestionTool extends BaseTool
{
    public function name(): string
    {
        return 'AskUserQuestion';
    }

    public function description(): string
    {
        return 'Pause and ask the host user one or more text or multiple-choice questions.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['text', 'multiple_choice']],
                            'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'required' => ['type' => 'boolean'],
                            'multiple' => ['type' => 'boolean'],
                        ],
                        'required' => ['question', 'type'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ], ['questions' => ['required', 'array', 'min:1']]);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        foreach ($input['questions'] ?? [] as $index => $question) {
            if (! is_array($question) || trim((string) ($question['question'] ?? '')) === '') {
                return "Question {$index} must contain non-empty question text.";
            }
            $type = $question['type'] ?? null;
            if (! in_array($type, ['text', 'multiple_choice'], true)) {
                return "Question {$index} has an invalid type.";
            }
            if (isset($question['required']) && ! is_bool($question['required'])) {
                return "Question {$index} required must be boolean.";
            }
            if (isset($question['multiple']) && ! is_bool($question['multiple'])) {
                return "Question {$index} multiple must be boolean.";
            }
            if ($type === 'multiple_choice') {
                $options = $question['options'] ?? null;
                if (! is_array($options) || count($options) < 2) {
                    return "Multiple-choice question {$index} requires at least two options.";
                }
                foreach ($options as $option) {
                    if (! is_string($option) || trim($option) === '') {
                        return "Multiple-choice question {$index} contains an empty option.";
                    }
                }
            } elseif (isset($question['options'])) {
                return "Text question {$index} cannot define multiple-choice options.";
            }
        }

        return null;
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        return ToolResult::error('AskUserQuestion must be resolved through a human interrupt.');
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}

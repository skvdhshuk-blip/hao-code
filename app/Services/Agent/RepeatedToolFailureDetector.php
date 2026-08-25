<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** Detects unchanged tool-error batches independently of provider tool IDs. @internal */
final class RepeatedToolFailureDetector
{
    public function __construct(private readonly int $maxRepeats = 3) {}

    public function detect(
        array $toolCalls,
        array $toolResults,
        ?string &$lastFingerprint,
        int &$repeatCount,
    ): bool {
        $resultsById = [];
        foreach ($toolResults as $result) {
            $id = $result['tool_use_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $resultsById[$id] = $result;
            }
        }
        $entries = [];
        $hasError = false;
        foreach ($toolCalls as $toolCall) {
            $result = $resultsById[$toolCall->id] ?? null;
            $isError = is_array($result) && ($result['is_error'] ?? false) === true;
            $entry = [
                'name' => $toolCall->name,
                'input' => $this->canonicalize($toolCall->input),
                'is_error' => $isError,
                'error' => null,
            ];
            if ($isError) {
                $hasError = true;
                $content = $result['content'] ?? '';
                if (! is_string($content)) {
                    $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $content = is_string($encoded) ? $encoded : get_debug_type($content);
                }
                $normalized = preg_replace('/\s+/u', ' ', trim($content));
                $entry['error'] = mb_substr($normalized ?? $content, 0, 512);
            }
            $entries[] = $entry;
        }
        if (! $hasError) {
            $lastFingerprint = null;
            $repeatCount = 0;

            return false;
        }
        $encoded = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $lastFingerprint = null;
            $repeatCount = 0;

            return false;
        }
        $fingerprint = hash('sha256', $encoded);
        if ($fingerprint === $lastFingerprint) {
            $repeatCount++;
        } else {
            $lastFingerprint = $fingerprint;
            $repeatCount = 1;
        }

        return $repeatCount >= $this->maxRepeats;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->canonicalize($child);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}

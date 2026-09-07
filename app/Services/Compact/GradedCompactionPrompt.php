<?php

namespace HaoCode\Services\Compact;

/**
 * Builds the compaction request and parses its graded response.
 *
 * The model returns one structured summary plus a keep-list: the indices of the
 * messages whose original text must survive verbatim, split into priority bands.
 * Which bands actually make it back into the history is decided later, by
 * ContextCompactor, from the context budget left at that moment — so the
 * compression level tracks the room that is actually available instead of being
 * fixed when the summary was written.
 *
 * Preserved originals are rendered as text inside the summary message. They are
 * never spliced back as real tool_result blocks: those must stay paired with the
 * tool_use block that produced them, and that pairing does not survive compaction.
 *
 * @internal
 */
final class GradedCompactionPrompt
{
    /** Highest band number the prompt asks for. Band 1 is the most load-bearing. */
    public const MAX_BAND = 3;

    /** Cap on how many indices one band may contribute, whatever the model asks for. */
    private const MAX_PER_BAND = 6;

    private const TRUNCATION_MARKER = "\n[... truncated when preserved during compaction]";

    /**
     * System prompt for the compaction request: the 9-section summary plus the
     * graded keep-list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function systemPrompt(): array
    {
        $maxBand = self::MAX_BAND;
        $maxPerBand = self::MAX_PER_BAND;

        return [[
            'type' => 'text',
            'text' => <<<PROMPT
<important>No tools may be used during this compact operation. Do not call any tools.</important>

You are a conversation compaction assistant. You MUST produce your response in exactly three blocks:

1. An `<analysis>` block where you draft your understanding
2. A `<summary>` block with the final structured output
3. One `<keep>` block per priority band

Your <summary> MUST contain these 9 sections in this exact format:

<summary>
# Conversation Summary

## 1. Primary Request and Intent
[What the user asked for — their exact words if possible, plus inferred intent]

## 2. Key Technical Concepts
[Important technical concepts, frameworks, patterns, algorithms discussed]

## 3. Files and Code Sections
[All files read, edited, or created. For each file, note what was done and any important code patterns. Use file:line format.]

## 4. Errors and Fixes
[Any errors encountered and how they were fixed]

## 5. Problem Solving
[Key decisions made, approaches tried, and why they were chosen]

## 6. All User Messages
[Bulleted list of every user message in order]

## 7. Pending Tasks
[Tasks mentioned but not yet completed]

## 8. Current Work
[What was being actively worked on when compaction was triggered]

## 9. Optional Next Step
[What the next logical step would be based on the current state]
</summary>

After the summary, decide which messages cannot be reduced to prose at all and must
survive word for word. Each message in the transcript is labelled `[#n]`. Sort those
indices into {$maxBand} priority bands and emit one <keep> block per band:

<keep band="1">12,15,23</keep>
<keep band="2">7,31</keep>
<keep band="3">4,9,18</keep>

- band 1: losing it blocks the work — source being edited, the failing stack trace, the user's original requirements
- band 2: losing it causes rework — confirmed interface signatures, configuration originals, verified command output
- band 3: useful background — supporting output that aids orientation

Rules: at most {$maxPerBand} indices per band; only indices that actually appear as `[#n]`
in the transcript; never list the same index in two bands. Emit an empty block
(for example `<keep band="3"></keep>`) when a band has nothing worth preserving.
Prefer few, high-value entries: everything you list competes for the same budget.

Be specific. Include file paths, function names, exact error messages. Preserve all context needed to continue the work seamlessly.
PROMPT,
        ]];
    }

    /**
     * Parse the `<keep band="n">` blocks out of a model response.
     *
     * Bands are returned in priority order, indices deduplicated across bands
     * (first band wins) and clamped to the transcript that was summarized. A
     * response with no keep blocks — an older prompt, a model that ignored the
     * instruction, or the basic-summary fallback — yields an empty array, which
     * puts the caller back on the pre-existing compaction behaviour.
     *
     * @return array<int, list<int>> band number (1-based) => message indices
     */
    public static function parseBands(string $text, int $messageCount): array
    {
        if ($messageCount <= 0 || ! preg_match_all(
            '/<keep\s+band\s*=\s*["\']?(\d+)["\']?\s*>(.*?)<\/keep>/is',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        $bands = [];
        $claimed = [];

        foreach ($matches as $match) {
            $band = (int) $match[1];
            if ($band < 1 || $band > self::MAX_BAND) {
                continue;
            }

            // Only digit runs that are not part of a longer token: a stray "-1"
            // must not silently become index 1.
            preg_match_all('/(?<![\d-])\d+/', $match[2], $tokens);

            foreach ($tokens[0] as $token) {
                $index = (int) $token;
                if ($index >= $messageCount || isset($claimed[$index])) {
                    continue;
                }
                if (count($bands[$band] ?? []) >= self::MAX_PER_BAND) {
                    break;
                }
                $claimed[$index] = true;
                $bands[$band][] = $index;
            }
        }

        foreach ($bands as $band => $indices) {
            sort($indices);
            $bands[$band] = $indices;
        }
        ksort($bands);

        return array_filter($bands, static fn (array $indices): bool => $indices !== []);
    }

    /**
     * Render one band's messages as verbatim text for re-insertion.
     *
     * @param  list<int>  $indices
     * @param  array<int, array<string, mixed>>  $messages  the transcript that was summarized
     */
    public static function renderBand(array $indices, array $messages, int $perItemCap): string
    {
        $toolNames = self::toolNameMap($messages);
        $parts = [];

        foreach ($indices as $index) {
            if (! isset($messages[$index]) || ! is_array($messages[$index])) {
                continue;
            }
            $rendered = self::renderMessage($index, $messages[$index], $toolNames, $perItemCap);
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        return implode("\n\n", $parts);
    }

    /** Strip keep blocks so they never leak into the summary text itself. */
    public static function stripKeepBlocks(string $text): string
    {
        return trim((string) preg_replace('/<keep\s+band\s*=\s*["\']?\d+["\']?\s*>.*?<\/keep>\s*/is', '', $text));
    }

    /**
     * Map tool_use ids to tool names so a preserved tool_result can say which
     * tool produced it — the result block itself only carries the id.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, string>
     */
    private static function toolNameMap(array $messages): array
    {
        $names = [];

        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'tool_use' && is_string($block['id'] ?? null)) {
                    $names[$block['id']] = (string) ($block['name'] ?? 'tool');
                }
            }
        }

        return $names;
    }

    /** @param array<string, string> $toolNames */
    private static function renderMessage(int $index, array $message, array $toolNames, int $perItemCap): string
    {
        $role = (string) ($message['role'] ?? 'unknown');
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            return $content === '' ? '' : self::wrap($index, $role, self::cap($content, $perItemCap));
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            $part = self::renderBlock($block, $toolNames, $perItemCap);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? '' : self::wrap($index, $role, implode("\n\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $toolNames
     */
    private static function renderBlock(array $block, array $toolNames, int $perItemCap): string
    {
        switch ($block['type'] ?? '') {
            case 'text':
                $text = (string) ($block['text'] ?? '');

                return $text === '' ? '' : self::cap($text, $perItemCap);

            case 'tool_use':
                $name = (string) ($block['name'] ?? 'unknown');
                $input = json_encode(
                    $block['input'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );

                return "[Tool call: {$name}]\n".self::cap(is_string($input) ? $input : '{}', $perItemCap);

            case 'tool_result':
                $id = is_string($block['tool_use_id'] ?? null) ? $block['tool_use_id'] : '';
                $name = $toolNames[$id] ?? 'tool';
                $flag = ($block['is_error'] ?? false) ? ' (error)' : '';
                $result = $block['content'] ?? '';
                if (! is_string($result)) {
                    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $result = is_string($encoded) ? $encoded : '';
                }

                return "[Tool result: {$name}{$flag}]\n".self::cap($result, $perItemCap);

            default:
                return '';
        }
    }

    private static function wrap(int $index, string $role, string $body): string
    {
        $role = preg_replace('/[^a-z_]/i', '', $role) ?: 'unknown';

        return "<preserved index=\"{$index}\" role=\"{$role}\">\n{$body}\n</preserved>";
    }

    private static function cap(string $text, int $perItemCap): string
    {
        if ($perItemCap <= 0 || mb_strlen($text) <= $perItemCap) {
            return $text;
        }

        return mb_substr($text, 0, $perItemCap).self::TRUNCATION_MARKER;
    }
}

<?php

declare(strict_types=1);

namespace HaoCode\Services\Api;

use HaoCode\Services\Agent\PromptFragment;

/** @internal */
final class ProviderPromptAdapter
{
    /**
     * @param list<PromptFragment> $fragments
     * @return array<int, array<string, mixed>>
     */
    public function adapt(array $fragments): array
    {
        $content = implode('', array_map(
            static fn (PromptFragment $fragment): string => $fragment->content,
            $fragments,
        ));
        $block = ['type' => 'text', 'text' => $content];
        $cacheable = $fragments !== [];
        foreach ($fragments as $fragment) {
            if ($fragment->stability !== PromptFragment::STABILITY_RUN) {
                $cacheable = false;
                break;
            }
        }
        if ($cacheable) {
            $block['cache_control'] = ['type' => 'ephemeral'];
        }

        return [$block];
    }
}

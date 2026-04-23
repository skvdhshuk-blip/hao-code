<?php

namespace HaoCode\Skill\Registry;

use HaoCode\Tools\Skill\SkillDefinition;

/**
 * Merges skills from multiple SkillSource instances.
 *
 * Collision rules:
 *  - Later-registered source wins (overwrites same name).
 *  - Conflicting names are also available under <alias>.<name> prefix.
 */
class SkillRegistry
{
    /** @var SkillSource[] */
    private array $sources = [];

    /** @var array<string, SkillDefinition>|null */
    private ?array $merged = null;

    public function addSource(SkillSource $source): void
    {
        $this->sources[] = $source;
        $this->merged = null; // invalidate cache
    }

    /**
     * @return SkillSource[]
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Merge all sources and return skills, with namespace collision handling.
     *
     * @return array<string, SkillDefinition>
     */
    public function mergeSources(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }

        /** @var array<string, array{SkillDefinition, string}> $seen name => [def, alias] */
        $seen = [];
        $result = [];

        foreach ($this->sources as $source) {
            $alias = $source->alias();
            $skills = $source->load();

            foreach ($skills as $name => $def) {
                if (isset($seen[$name])) {
                    // Register the first occurrence under its prefixed alias too
                    [, $prevAlias] = $seen[$name];
                    $prefixedPrev = "{$prevAlias}.{$name}";
                    if (! isset($result[$prefixedPrev])) {
                        $result[$prefixedPrev] = $seen[$name][0];
                    }
                }

                // Later source overwrites
                $result[$name] = $def;
                // Also register under prefixed namespace
                $result["{$alias}.{$name}"] = $def;
                $seen[$name] = [$def, $alias];
            }
        }

        $this->merged = $result;

        return $result;
    }

    /**
     * Force-reload merged cache (useful after sync).
     */
    public function invalidate(): void
    {
        $this->merged = null;
    }

    /**
     * Sync all syncable sources and return total skills fetched.
     */
    public function syncAll(): int
    {
        $total = 0;
        foreach ($this->sources as $source) {
            if ($source->isSyncable()) {
                $result = $source->sync();
                if ($result > 0) {
                    $total += $result;
                }
            }
        }
        $this->invalidate();

        return $total;
    }
}

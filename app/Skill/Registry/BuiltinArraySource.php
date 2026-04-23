<?php

namespace HaoCode\Skill\Registry;

use HaoCode\Tools\Skill\SkillDefinition;

/**
 * Wraps an already-loaded array of SkillDefinitions as a SkillSource.
 * Used to inject builtin / filesystem skills into the SkillRegistry.
 */
class BuiltinArraySource implements SkillSource
{
    /**
     * @param  array<string, SkillDefinition>  $skills
     */
    public function __construct(
        private readonly string $sourceAlias,
        private readonly array $skills,
    ) {}

    public function alias(): string
    {
        return $this->sourceAlias;
    }

    public function load(): array
    {
        return $this->skills;
    }

    public function sync(): int
    {
        return count($this->skills);
    }

    public function isSyncable(): bool
    {
        return false;
    }
}

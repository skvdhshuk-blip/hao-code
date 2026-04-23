<?php

namespace HaoCode\Skill\Registry;

use HaoCode\Tools\Skill\SkillDefinition;

/**
 * A source that provides skill definitions.
 */
interface SkillSource
{
    /**
     * Return a human-readable alias for this source (used for namespace prefix).
     */
    public function alias(): string;

    /**
     * Load and return all skill definitions from this source.
     *
     * @return array<string, SkillDefinition> keyed by skill name
     */
    public function load(): array;

    /**
     * Synchronize / refresh this source (e.g. git pull, directory rescan).
     * Returns number of skills fetched.
     */
    public function sync(): int;

    /**
     * Whether this source supports on-demand sync (e.g. remote git repos do; builtins don't).
     */
    public function isSyncable(): bool;
}

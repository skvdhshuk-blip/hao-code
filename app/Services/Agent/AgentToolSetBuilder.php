<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Tools\AskUserQuestion\AskUserQuestionTool;
use HaoCode\Tools\Config\ConfigTool;
use HaoCode\Tools\Memory\MemoryDeleteTool;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\Memory\MemoryWriteTool;
use HaoCode\Tools\Skill\SkillCapability;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillModelResolver;
use HaoCode\Tools\Skill\SkillTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

/** Owns capability inheritance, filtering, and run-scoped tool replacement. @internal */
final class AgentToolSetBuilder
{
    public function __construct(private readonly ToolRegistry $baseRegistry) {}

    /** @param callable(AgentLoopSpec): AgentLoop $childLoopFactory */
    public function build(
        AgentLoopSpec $invocation,
        ?AgentRunContext $runContext,
        callable $childLoopFactory,
    ): ToolRegistry {
        $parentRegistry = $invocation->parentToolRegistry ?? $this->baseRegistry;
        $parentTools = $invocation->parentToolRegistry?->getAllTools();
        $toolRegistry = $this->cloneRegistry(
            $parentRegistry,
            $invocation->toolFilter,
            $invocation->additionalTools !== []
                || $invocation->replacementTools !== []
                || $runContext !== null,
        );
        $additionalFilter = $invocation->additionalToolFilter
            ?? $invocation->toolFilter
            ?? static fn (string $name): bool => true;
        if ($invocation->toolFilter !== null && $invocation->additionalToolFilter !== null) {
            $toolFilter = $invocation->toolFilter;
            $explicitFilter = $invocation->additionalToolFilter;
            $additionalFilter = static fn (string $name): bool =>
                $toolFilter($name) && $explicitFilter($name);
        }

        foreach ($invocation->replacementTools as $tool) {
            if (! $additionalFilter($tool->name())) {
                continue;
            }
            if ($parentTools !== null) {
                $this->assertInheritedIdentity($parentTools, $tool->name(), $tool::class, true);
                continue;
            }
            $toolRegistry->replace($tool);
        }
        foreach ($invocation->additionalTools as $tool) {
            if (! $additionalFilter($tool->name())) {
                continue;
            }
            if ($parentTools !== null) {
                $this->assertInheritedIdentity($parentTools, $tool->name(), $tool::class, false);
                continue;
            }
            $toolRegistry->register($tool);
        }

        $parentAllows = static fn (string $name): bool =>
            $parentTools === null || isset($parentTools[$name]);
        if ($runContext !== null) {
            $memoryStore = $runContext->memoryStore ?? new JsonMemoryStore;
            foreach (['MemoryRead', 'MemoryWrite', 'MemoryDelete'] as $name) {
                $toolRegistry->unregister($name);
            }
            foreach ([
                new MemoryReadTool($memoryStore),
                new MemoryWriteTool($memoryStore),
                new MemoryDeleteTool($memoryStore),
            ] as $memoryTool) {
                if ($parentAllows($memoryTool->name())
                    && in_array($memoryTool->name(), $runContext->memoryTools, true)
                    && ($invocation->toolFilter === null || ($invocation->toolFilter)($memoryTool->name()))) {
                    $toolRegistry->register($memoryTool);
                }
            }
        }

        if ($runContext?->enableAskUser && $parentAllows('AskUserQuestion')
            && ! $toolRegistry->has('AskUserQuestion')) {
            $toolRegistry->register(new AskUserQuestionTool);
        }
        if ($runContext === null) {
            return $toolRegistry;
        }

        if ($parentAllows('Skill')
            && ($invocation->toolFilter === null || ($invocation->toolFilter)('Skill'))) {
            $skillTool = new SkillTool(
                skillLoader: $runContext->skillLoader,
                forkRunner: function (
                    string $prompt,
                    SkillDefinition $skill,
                    ToolUseContext $context,
                ) use ($childLoopFactory): string {
                    if ($context->runContext === null) {
                        throw new \RuntimeException('Forked skills require an active agent run context.');
                    }
                    $childContext = $context->runContext->fork($context->workingDirectory);
                    if ($skill->model !== null && trim($skill->model) !== '') {
                        $model = SkillModelResolver::resolve(
                            trim($skill->model),
                            $childContext->settings->getProviderType(),
                        );
                        if ($model !== null) {
                            $childContext->settings->set('model', $model);
                        }
                    }
                    $capabilities = SkillCapability::normalizeSpecs($skill->allowedTools);
                    $filter = $capabilities === []
                        ? null
                        : static fn (string $name): bool => in_array(
                            $name,
                            SkillCapability::toolNames($capabilities),
                            true,
                        );
                    $loop = $childLoopFactory(new AgentLoopSpec(
                        toolFilter: $filter,
                        workingDirectory: $context->workingDirectory,
                        provider: $context->provider,
                        runContext: $childContext,
                        ephemeral: ! ($childContext->interruptOn !== [] || $childContext->enableAskUser),
                        afterFork: true,
                        parentToolRegistry: $context->toolRegistry,
                        parentRunContext: $context->runContext,
                        limits: RunLimits::turns(20),
                    ));
                    if ($capabilities !== []) {
                        $loop->setBaseSkillScope($capabilities);
                    }

                    return (new AgentInvocation($prompt))->invoke($loop)->text;
                },
            );
            if ($toolRegistry->has('Skill')) {
                $toolRegistry->replace($skillTool);
            } else {
                $toolRegistry->register($skillTool);
            }
        }
        if ($parentAllows('Config')
            && ($invocation->toolFilter === null || ($invocation->toolFilter)('Config'))) {
            $configTool = new ConfigTool($runContext->settings);
            if ($toolRegistry->has('Config')) {
                $toolRegistry->replace($configTool);
            } else {
                $toolRegistry->register($configTool);
            }
        }

        return $toolRegistry;
    }

    public function cloneRegistry(
        ToolRegistry $parent,
        ?callable $filter,
        bool $forceClone = false,
    ): ToolRegistry {
        if ($filter === null && ! $forceClone) {
            return $parent;
        }
        $filtered = new ToolRegistry;
        foreach ($parent->getAllTools() as $tool) {
            if ($filter !== null && ! $filter($tool->name())) {
                continue;
            }
            $reflection = new \ReflectionObject($tool);
            $filtered->register($reflection->isCloneable() ? clone $tool : $tool);
        }

        return $filtered;
    }

    /** @param array<string, \HaoCode\Contracts\ToolInterface> $parentTools */
    private function assertInheritedIdentity(
        array $parentTools,
        string $name,
        string $class,
        bool $replacement,
    ): void {
        $parent = $parentTools[$name] ?? null;
        $kind = $replacement ? 'replacement capability' : 'capability';
        if ($parent === null) {
            throw new \LogicException("Nested agent cannot add {$kind} '{$name}' absent from its parent.");
        }
        if ($parent::class !== $class) {
            throw new \LogicException(
                "Nested agent cannot replace parent capability '{$name}' with another implementation.",
            );
        }
    }
}

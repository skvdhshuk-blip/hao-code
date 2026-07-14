#!/usr/bin/env php
<?php

declare(strict_types=1);

use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @return array{iterations: int, total_ms: float, average_us: float, peak_memory_mb: float}
 */
function benchmark(int $iterations, callable $operation): array
{
    $operation();
    gc_collect_cycles();

    $startedAt = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $operation();
    }
    $elapsedNanoseconds = hrtime(true) - $startedAt;

    return [
        'iterations' => $iterations,
        'total_ms' => round($elapsedNanoseconds / 1_000_000, 3),
        'average_us' => round($elapsedNanoseconds / $iterations / 1_000, 3),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    ];
}

function createGitFixture(): string
{
    $directory = sys_get_temp_dir().'/haocode-git-benchmark-'.getmypid();
    if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create Git benchmark fixture {$directory}.");
    }

    $commands = [
        'init -q -b main',
        'config user.email benchmark@example.com',
        'config user.name Benchmark',
    ];
    foreach ($commands as $arguments) {
        exec('git -C '.escapeshellarg($directory).' '.$arguments.' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("Unable to initialize Git benchmark fixture: {$arguments}.");
        }
    }

    file_put_contents($directory.'/tracked.txt', "baseline\n");
    exec('git -C '.escapeshellarg($directory).' add tracked.txt 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Unable to stage Git benchmark fixture.');
    }
    exec('git -C '.escapeshellarg($directory).' commit -qm initial 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Unable to commit Git benchmark fixture.');
    }
    file_put_contents($directory.'/untracked.txt', "fixture\n");

    return $directory;
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/**
 * @return array<string, array{iterations: int, total_ms: float, average_us: float, peak_memory_mb: float}>
 */
function runBenchmarks(): array
{
    SdkRuntime::reset();
    SdkRuntime::boot(basePath: dirname(__DIR__));

    /** @var ToolRegistry $toolRegistry */
    $toolRegistry = SdkRuntime::app(ToolRegistry::class);
    $gitFixtureDirectory = createGitFixture();
    $gitContext = new GitContext($gitFixtureDirectory);

    $memoryPath = sys_get_temp_dir().'/haocode-runtime-benchmark-'.getmypid().'.json';
    $entries = [];
    for ($index = 0; $index < 1_000; $index++) {
        $value = "Memory {$index}: ".str_repeat('performance fixture ', 12);
        $entries['memory_'.$index] = [
            'value' => $value,
            'type' => 'benchmark',
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'l0' => "Memory {$index} summary.",
            'l1' => $value,
            'l0_tokens' => 5,
            'l1_tokens' => 50,
            'l2_tokens' => 50,
            'summary_mode' => 'benchmark',
            'summary_generated_at' => '2026-01-01T00:00:00+00:00',
        ];
    }
    file_put_contents(
        $memoryPath,
        json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $memoryStore = new JsonMemoryStore($memoryPath);
    $projectDirectory = dirname(__DIR__);
    $contextBuilder = new ContextBuilder(
        settings: new SettingsManager($projectDirectory),
        toolRegistry: $toolRegistry,
        memoryStore: $memoryStore,
        skillLoader: new SkillLoader($projectDirectory),
        gitContext: new GitContext($gitFixtureDirectory),
        outputStyleLoader: new OutputStyleLoader($projectDirectory),
        workingDirectory: $projectDirectory,
    );

    try {
        $results = [
            'tool_schema' => benchmark(5_000, static fn (): array => $toolRegistry->toApiTools()),
            'memory_read' => benchmark(500, static fn (): ?string => $memoryStore->read('memory_500', 'l0')),
            'memory_all' => benchmark(100, static fn (): array => $memoryStore->all('l0')),
            'git_context' => benchmark(10, static fn (): string => $gitContext->getDiffContext()),
            'system_prompt' => benchmark(10, static fn (): array => $contextBuilder->buildSystemPrompt()),
        ];

        file_put_contents($gitFixtureDirectory.'/tracked.txt', "changed\n");
        $results['git_context_with_diff'] = benchmark(
            10,
            static fn (): string => $gitContext->getDiffContext(),
        );

        return $results;
    } finally {
        @unlink($memoryPath);
        @unlink($memoryPath.'.lock');
        removeDirectory($gitFixtureDirectory);
    }
}

$results = runBenchmarks();

if (in_array('--json', $argv, true)) {
    fwrite(STDOUT, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

fwrite(STDOUT, sprintf("%-18s %12s %14s %12s\n", 'benchmark', 'iterations', 'average_us', 'total_ms'));
foreach ($results as $name => $result) {
    fwrite(STDOUT, sprintf(
        "%-18s %12d %14.3f %12.3f\n",
        $name,
        $result['iterations'],
        $result['average_us'],
        $result['total_ms'],
    ));
}

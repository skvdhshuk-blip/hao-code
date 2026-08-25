<?php

namespace HaoCode\Services\Hooks;

use HaoCode\Services\Settings\SettingsFileStore;

class HookExecutor
{
    /** @var array<string, HookDefinition[]> */
    private array $hooks = [];
    private HookProcessRunner $processRunner;
    private SettingsFileStore $fileStore;

    public function __construct(
        private readonly ?string $workingDirectory = null,
        ?string $globalSettingsPath = null,
    )
    {
        $this->processRunner = new HookProcessRunner;
        $this->fileStore = new SettingsFileStore($workingDirectory, $globalSettingsPath);
        $this->loadHooks();
    }

    /**
     * Run hooks for a given event.
     *
     * @return HookResult
     */
    public function execute(
        string $event,
        array $context = [],
        ?callable $shouldAbort = null,
    ): HookResult
    {
        if ($this->abortRequested($shouldAbort)) {
            return new HookResult(allowed: false, output: 'Hook execution aborted');
        }

        $hooks = $this->hooks[$event] ?? [];
        $outputs = [];
        $modifiedInput = null;

        foreach ($hooks as $hook) {
            if ($this->abortRequested($shouldAbort)) {
                return new HookResult(allowed: false, output: 'Hook execution aborted');
            }

            // Skip hook if matcher is set and does not match the tool name
            if ($hook->matcher !== null) {
                $toolName = $context['tool'] ?? '';
                if (!fnmatch($hook->matcher, $toolName)) {
                    continue;
                }
            }

            $hookResult = $this->runHook($event, $hook, $context, $shouldAbort);

            if (!$hookResult->allowed) {
                return $hookResult;
            }

            // Accumulate outputs from all hooks
            if ($hookResult->output !== '' && $hookResult->output !== null) {
                $outputs[] = $hookResult->output;
            }

            // Merge any modifications - each hook sees previous modifications
            if ($hookResult->modifiedInput !== null) {
                $context['input'] = $hookResult->modifiedInput;
                $modifiedInput = $hookResult->modifiedInput;
            }
        }

        return new HookResult(
            allowed: true,
            modifiedInput: $modifiedInput,
            output: implode("\n", $outputs),
        );
    }

    public function hasHooksFor(string $event, ?string $toolName = null): bool
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            if ($toolName === null || $hook->matcher === null || fnmatch($hook->matcher, $toolName)) {
                return true;
            }
        }

        return false;
    }

    private function runHook(
        string $event,
        HookDefinition $hook,
        array $context,
        ?callable $shouldAbort,
    ): HookResult
    {
        $command = $hook->command;

        // Inject context as environment variables
        $env = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $env['HOOK_' . strtoupper($key)] = (string) $value;
            }
        }

        // Also pass context as JSON on stdin
        $stdin = json_encode($context, JSON_UNESCAPED_UNICODE);

        $processResult = $this->processRunner->run(
            command: $command,
            stdin: is_string($stdin) ? $stdin : '{}',
            workingDirectory: $this->workingDirectory,
            environment: $env,
            shouldAbort: $shouldAbort,
        );

        if ($processResult['aborted']) {
            return new HookResult(allowed: false, output: 'Hook execution aborted');
        }
        if (! $processResult['started']) {
            return $this->processFailure($event, 'Failed to execute hook: '.$processResult['error']);
        }
        if ($processResult['timedOut']) {
            return $this->processFailure($event, 'Hook timed out');
        }
        if ($processResult['outputLimitExceeded'] !== null) {
            return $this->processFailure(
                $event,
                "Hook {$processResult['outputLimitExceeded']} exceeded the output limit",
            );
        }
        if ($processResult['error'] !== null) {
            return $this->processFailure($event, 'Hook process failed: '.$processResult['error']);
        }

        $stdout = $processResult['stdout'];
        $stderr = $processResult['stderr'];
        $exitCode = $processResult['exitCode'];

        if ($exitCode !== 0) {
            return new HookResult(
                allowed: false,
                output: "Hook failed (exit code ".($exitCode ?? 'unknown').'): '.trim($stderr ?: $stdout),
            );
        }

        // Parse stdout for hook decisions
        $output = trim($stdout);

        if (in_array(strtolower($output), ['deny', 'block', 'no'])) {
            return new HookResult(allowed: false, output: 'Denied by hook');
        }

        // Check for JSON output with modifications
        $json = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $invalidDecision = ! is_array($json)
                || str_starts_with($output, '[')
                || (array_key_exists('allow', $json) && ! is_bool($json['allow']))
                || (array_key_exists('input', $json) && ! is_array($json['input']))
                || (array_key_exists('message', $json) && ! is_string($json['message']));
            if ($invalidDecision) {
                return $this->invalidOutput($event, $output);
            }

            return new HookResult(
                allowed: ($json['allow'] ?? true) !== false,
                modifiedInput: $json['input'] ?? null,
                output: $json['message'] ?? '',
            );
        }
        if ($output !== '' && (str_starts_with($output, '{') || str_starts_with($output, '['))) {
            return $this->invalidOutput($event, $output);
        }

        return new HookResult(allowed: true, output: $output);
    }

    private function processFailure(string $event, string $message): HookResult
    {
        return new HookResult(
            allowed: $event !== 'PreToolUse',
            output: $message,
        );
    }

    private function invalidOutput(string $event, string $output): HookResult
    {
        if ($event !== 'PreToolUse') {
            return new HookResult(allowed: true, output: $output);
        }

        return new HookResult(
            allowed: false,
            output: 'Hook returned an invalid decision',
        );
    }

    private function abortRequested(?callable $shouldAbort): bool
    {
        if ($shouldAbort === null) {
            return false;
        }

        try {
            return (bool) $shouldAbort();
        } catch (\Throwable) {
            return true;
        }
    }

    private function loadHooks(): void
    {
        foreach ($this->fileStore->paths() as $path) {
            $settings = $this->fileStore->read($path);
            $hooks = $settings['hooks'] ?? [];
            if (! is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $event => $eventHooks) {
                if (! is_string($event) || ! is_array($eventHooks)) {
                    continue;
                }
                foreach ($eventHooks as $hookConfig) {
                    if (is_array($hookConfig) && is_string($hookConfig['command'] ?? null)) {
                        $this->hooks[$event][] = new HookDefinition(
                            event: $event,
                            command: $hookConfig['command'],
                            matcher: is_string($hookConfig['matcher'] ?? null)
                                ? $hookConfig['matcher']
                                : null,
                        );
                    }
                }
            }
        }
    }
}

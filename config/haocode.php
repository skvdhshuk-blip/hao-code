<?php

$environment = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'empty', '(empty)' => '',
        'null', '(null)' => null,
        default => $value,
    };
};
$defaultSessionPath ??= dirname(__DIR__).'/storage/app/haocode/sessions';

return [
    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    */
    'model' => $environment('HAOCODE_MODEL', 'claude-sonnet-4-20250514'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    */
    'api_key' => $environment('ANTHROPIC_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    */
    'api_base_url' => $environment('HAOCODE_API_BASE_URL', 'https://api.anthropic.com'),

    /*
    |--------------------------------------------------------------------------
    | Active Provider
    |--------------------------------------------------------------------------
    */
    'active_provider' => $environment('HAOCODE_ACTIVE_PROVIDER', null),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    */
    'max_tokens' => (int) $environment('HAOCODE_MAX_TOKENS', 16384),

    /*
    |--------------------------------------------------------------------------
    | Model Context Window
    |--------------------------------------------------------------------------
    */
    'context_window' => (int) $environment('HAOCODE_CONTEXT_WINDOW', 200000),

    /*
    |--------------------------------------------------------------------------
    | Permission Mode
    |--------------------------------------------------------------------------
    | Public Claude-style config surface. Hao Code derives internal approval
    | and sandbox behavior from this setting.
    | Supported: default, plan, accept_edits, bypass_permissions
    */
    'permission_mode' => $environment('HAOCODE_PERMISSION_MODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Session Storage Path
    |--------------------------------------------------------------------------
    */
    'session_path' => $environment('HAOCODE_SESSION_PATH', $defaultSessionPath),

    /*
    |--------------------------------------------------------------------------
    | Settings Paths
    |--------------------------------------------------------------------------
    */
    'global_settings_path' => $environment('HAOCODE_GLOBAL_SETTINGS_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Background Agent Lifecycle
    |--------------------------------------------------------------------------
    */
    'background_agent_idle_timeout' => (int) $environment('HAOCODE_BACKGROUND_AGENT_IDLE_TIMEOUT', 300),
    'background_agent_poll_interval_ms' => (int) $environment('HAOCODE_BACKGROUND_AGENT_POLL_INTERVAL_MS', 250),

    /*
    |--------------------------------------------------------------------------
    | API Stream Stall Detection
    |--------------------------------------------------------------------------
    */
    'api_stream_idle_timeout' => (int) $environment('HAOCODE_API_STREAM_IDLE_TIMEOUT', 60),
    'api_stream_poll_timeout' => (float) $environment('HAOCODE_API_STREAM_POLL_TIMEOUT', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Extended Thinking
    |--------------------------------------------------------------------------
    */
    'thinking_enabled' => filter_var($environment('HAOCODE_THINKING', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
    'thinking_budget' => (int) $environment('HAOCODE_THINKING_BUDGET', 10000),

    /*
    |--------------------------------------------------------------------------
    | Effort Level (low, medium, high, max, auto)
    |--------------------------------------------------------------------------
    */
    'effort_level' => $environment('HAOCODE_EFFORT_LEVEL', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Editor Mode (normal, vim)
    |--------------------------------------------------------------------------
    */
    'editor_mode' => $environment('HAOCODE_EDITOR_MODE', 'normal'),

    /*
    |--------------------------------------------------------------------------
    | Cost Thresholds
    |--------------------------------------------------------------------------
    */
    'cost_warn_threshold' => (float) $environment('HAOCODE_COST_WARN', 5.00),
    'cost_stop_threshold' => (float) $environment('HAOCODE_COST_STOP', 50.00),
];

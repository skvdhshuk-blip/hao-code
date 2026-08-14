<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\Bash\ReadOnlyCommandSafety;

/**
 * Deterministic risk classifier for the smart HITL mode.
 *
 * Ports rules R0-R5 from the hao-work bridge layer (hitl-policy.php) plus the
 * codex guardian red lines (credential access, exfiltration, persistent
 * security weakening) into the SDK. Everything is fail-closed: unknown tools,
 * malformed input, unresolvable paths, and classifier errors all degrade to
 * manual human review, never to silent approval.
 *
 * Levels:
 * - auto_allow: rule-approved without a model review.
 * - gray:       not allowlisted, not a red line; eligible for model review.
 * - red_line:   hard rule hit; always escalates to a human, no model review.
 * - ask:        unknown/malformed/fail-closed; always escalates to a human.
 */
final class HitlPolicy
{
    use HitlPolicyClassifyActionConcern;
    use HitlPolicyCheckRedirectsConcern;

    public const AUTO_ALLOW = 'auto_allow';
    public const GRAY = 'gray';
    public const RED_LINE = 'red_line';
    public const ASK = 'ask';

    private const MAX_WRITE_BYTES = 1048576; // 1 MiB

    private const KNOWN_TOOLS = [
        'Read', 'Glob', 'Grep', 'LSP', 'Write', 'Edit', 'apply_patch', 'Bash',
        'TodoWrite', 'MemoryRead', 'MemoryWrite', 'Skill', 'AskUserQuestion',
    ];

    /**
     * Shell obfuscation / arbitrary-execution markers.
     *
     * $() and backticks are intentionally absent: command substitutions are
     * extracted and rated recursively (codex-style) instead of being rejected
     * wholesale. ${} expansion, $IFS manipulation, and control characters stay
     * red lines.
     */
    private const OBFUSCATION_PATTERNS = [
        '/\$\{/' => 'parameter expansion ${}',
        '/\$IFS/' => 'IFS manipulation',
        '/[\x00-\x08\x0B\x0C\x7F]/' => 'control characters',
    ];

    /** Maximum nesting depth for recursive command substitution rating. */
    private const MAX_SUBST_DEPTH = 2;

    /** Commands that only read state and are safe to auto-allow (R4 allowlist). */
    private const SIMPLE_ALLOWLIST = [
        'pwd', 'ls', 'cat', 'head', 'tail', 'wc', 'file', 'stat', 'du', 'df',
        'tree', 'which', 'echo', 'printf', 'date', 'grep', 'rg', 'true', 'false',
        'hostname', 'whoami', 'uname', 'basename', 'dirname', 'realpath', 'readlink',
        'sort', 'uniq', 'comm', 'diff', 'cut', 'tr', 'jq', 'yq', 'more', 'less',
        'awk', 'zipinfo', 'nproc', 'cd',
    ];

    /** Commands that are always a red line regardless of arguments. */
    private const HARD_RED_COMMANDS = [
        'sudo' => 'privilege escalation',
        'su' => 'privilege escalation',
        'doas' => 'privilege escalation',
        'dd' => 'raw disk writes',
        'mkfs' => 'filesystem formatting',
        'shred' => 'secure data destruction',
        'fdisk' => 'partition table modification',
        'mount' => 'filesystem mount',
        'umount' => 'filesystem unmount',
        'eval' => 'dynamic code execution',
        'exec' => 'process replacement',
        'source' => 'executes file contents',
        'xargs' => 'argument-driven execution',
        'base64' => 'encoded payload handling',
        'env' => 'environment dumping',
        'printenv' => 'environment dumping',
        'history' => 'shell history exposure',
        'crontab' => 'scheduled task modification',
        'launchctl' => 'service manager control',
        'systemctl' => 'service manager control',
        'chown' => 'ownership change',
        'security' => 'keychain access',
        'curl' => 'network egress',
        'wget' => 'network egress',
        'nc' => 'raw network access',
        'ncat' => 'raw network access',
        'scp' => 'network file transfer',
        'rsync' => 'network file transfer',
        'ssh' => 'remote shell',
        'sftp' => 'network file transfer',
        'ftp' => 'network file transfer',
        'telnet' => 'remote shell',
    ];

    /** find actions that mutate files or execute arbitrary commands. */
    private const FIND_MUTATING_ACTIONS = [
        '-delete', '-exec', '-execdir', '-ok', '-okdir',
        '-fprint', '-fprint0', '-fprintf', '-fls',
    ];
}

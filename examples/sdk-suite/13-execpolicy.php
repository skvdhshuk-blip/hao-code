#!/usr/bin/env php
<?php
// Env: none required
require __DIR__.'/_bootstrap.php';
use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;

$tmpPolicy = sys_get_temp_dir().'/haocode-policy-'.getmypid().'.yml';
file_put_contents($tmpPolicy, <<<'YAML'
rules:
  - name: allow-git-status
    tool: Bash
    cmd: git
    args_match: ["status*"]
    risk: normal
    allow_chain: false
    allow_auto: true
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]

  - name: allow-git-log
    tool: Bash
    cmd: git
    args_match: ["log*"]
    risk: normal
    allow_chain: false
    allow_auto: true
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]

  - name: deny-rm
    tool: Bash
    cmd: rm
    risk: high
    allow_chain: false
    allow_auto: false
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
YAML);

$matcher = new PolicyMatcher((new PolicyLoader)->load($tmpPolicy));
@unlink($tmpPolicy);

$cases = [
    ['Bash', 'git', ['args' => 'status']],
    ['Bash', 'git', ['args' => 'log --oneline -10']],
    ['Bash', 'rm',  ['args' => '-rf /']],
    ['Bash', 'ls',  ['args' => '-la', 'env' => ['LD_PRELOAD' => '/evil.so']]],
];
printf("%-8s %-6s %-25s %s\n", 'Tool', 'Allow', 'Command', 'Reason');
echo str_repeat('-', 65)."\n";
foreach ($cases as [$tool, $cmd, $ctx]) {
    $d = $matcher->match($tool, $cmd, $ctx);
    printf("%-8s %-6s %-25s %s\n", $tool, $d->isAllowed() ? 'YES' : 'NO', "{$cmd} {$ctx['args']}", $d->reason ?? '-');
}

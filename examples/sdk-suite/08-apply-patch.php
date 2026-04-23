#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$workdir = sys_get_temp_dir().'/haocode-patch-'.getmypid();
mkdir($workdir, 0755, true);

$result = HaoCode::query(
    "Use apply_patch to create {$workdir}/hello.txt with content \"Hello, ApplyPatch!\".\n".
    "Envelope:\n*** Begin Patch\n*** Add File: {$workdir}/hello.txt\nHello, ApplyPatch!\n*** End Patch",
    new HaoCodeConfig(apiKey: getenv('ANTHROPIC_API_KEY'), cwd: $workdir, maxTurns: 3, allowedTools: ['apply_patch'])
);
echo $result."\n";
if (file_exists("{$workdir}/hello.txt")) {
    echo "File: ".trim(file_get_contents("{$workdir}/hello.txt"))."\n";
}
@unlink("{$workdir}/hello.txt"); @rmdir($workdir);

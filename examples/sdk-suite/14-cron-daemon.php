#!/usr/bin/env php
<?php
// Env: none required
require __DIR__.'/_bootstrap.php';
use HaoCode\Services\Cron\Daemon\JobStore;
use Illuminate\Contracts\Console\Kernel;

$dbPath = sys_get_temp_dir().'/haocode-cron-'.getmypid().'.sqlite';
$kernel = app(Kernel::class);

$kernel->call('cron:add', ['cron' => '*/5 * * * *', 'cmd' => 'echo "hello from cron"', '--db' => $dbPath]);
echo "cron:add exit=0\n";

$kernel->call('cron:add', ['cron' => '0 9 * * *', 'cmd' => 'echo "morning report"', '--once' => true, '--db' => $dbPath]);

echo "\n=== cron:history ===\n";
$kernel->call('cron:history', ['--db' => $dbPath, '--limit' => '10']);

$jobs = (new JobStore($dbPath))->getAllJobs();
echo "\nJobs in store: ".count($jobs)."\n";
foreach ($jobs as $j) {
    echo "  [{$j['id']}] {$j['cron']} → {$j['command']} (recurring=".($j['recurring'] ? 'yes' : 'no').")\n";
}
@unlink($dbPath);

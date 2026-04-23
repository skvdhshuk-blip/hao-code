#!/usr/bin/env php
<?php
// Env: none required
require __DIR__.'/_bootstrap.php';
use Illuminate\Contracts\Console\Kernel;

$kernel = app(Kernel::class);
echo "=== skills:list ===\n";
$kernel->call('skills:list');
echo "\nTo add a git source:\n";
echo "  php bin/hao-code skills:add-source https://github.com/yourorg/skills my-skills\n";
echo "  php bin/hao-code skills:update\n";

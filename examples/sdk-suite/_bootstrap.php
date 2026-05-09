<?php
// Shared bootstrap for sdk-suite demos. Usage: require __DIR__.'/_bootstrap.php';
$packageRoot = dirname(__DIR__, 2);
require_once $packageRoot.'/vendor/autoload.php';

\HaoCode\Support\Runtime\SdkRuntime::boot(basePath: $packageRoot);

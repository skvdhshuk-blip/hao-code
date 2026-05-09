<?php

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

return \HaoCode\Support\Runtime\SdkRuntime::boot(basePath: dirname(__DIR__));

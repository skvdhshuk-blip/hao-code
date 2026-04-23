<?php
// Shared bootstrap for sdk-suite demos. Usage: require __DIR__.'/_bootstrap.php';
$packageRoot = dirname(__DIR__, 2);
require_once $packageRoot.'/vendor/autoload.php';
use HaoCode\Support\Runtime\StoragePathResolver;
use Illuminate\Contracts\Console\Kernel;
$_resolver = new StoragePathResolver;
$_storage  = $_resolver->resolve($packageRoot, $packageRoot.'/vendor/autoload.php');
if ($_storage) {
    if (!is_dir($_storage)) mkdir($_storage, 0755, true);
    putenv("LARAVEL_STORAGE_PATH={$_storage}");
    $_ENV['LARAVEL_STORAGE_PATH'] = $_storage;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $_storage;
}
$app = require $packageRoot.'/bootstrap/app.php';
if ($_storage) $app->useStoragePath($_storage);
$app->make(Kernel::class)->bootstrap();

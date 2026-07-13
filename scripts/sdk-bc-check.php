#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SDK BC (Backward Compatibility) check script.
 *
 * Reflects over HaoCode\Sdk\* classes and generates a snapshot of all
 *
 * @api-annotated public methods and properties.
 *
 * Usage:
 *   php scripts/sdk-bc-check.php --write    # Generate/overwrite snapshot
 *   php scripts/sdk-bc-check.php --verify   # Compare current state vs snapshot; exit 1 on diff
 */

require_once __DIR__.'/../vendor/autoload.php';

use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Conversation;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HaoCodeSdkServiceProvider;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkSkill;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;

const SNAPSHOT_PATH = __DIR__.'/../tests/Sdk/Fixtures/public-api.snapshot.json';

$mode = $argv[1] ?? '--verify';

if (! in_array($mode, ['--write', '--verify'], true)) {
    fwrite(STDERR, "Usage: php sdk-bc-check.php [--write|--verify]\n");
    exit(2);
}

$classes = [
    HaoCode::class,
    HaoCodeConfig::class,
    HumanActionRequest::class,
    HumanDecision::class,
    HumanInterrupt::class,
    HumanInterruptException::class,
    Conversation::class,
    QueryResult::class,
    SandboxConfig::class,
    Message::class,
    SdkTool::class,
    SdkSkill::class,
    AbortController::class,
    StructuredResult::class,
    HaoCodeSdkServiceProvider::class,
];

/**
 * Returns true if the doc comment contains @api.
 */
function isApi(?string $docComment): bool
{
    if ($docComment === null || $docComment === false) {
        return false;
    }

    return (bool) preg_match('/@api\b/', $docComment);
}

/**
 * Returns true if the doc comment contains @internal.
 */
function isInternal(?string $docComment): bool
{
    if ($docComment === null || $docComment === false) {
        return false;
    }

    return (bool) preg_match('/@internal\b/', $docComment);
}

/**
 * Build a canonical signature string for a method.
 */
function methodSignature(ReflectionMethod $method): string
{
    $parts = [];
    if ($method->isPublic()) {
        $parts[] = 'public';
    } elseif ($method->isProtected()) {
        $parts[] = 'protected';
    } else {
        $parts[] = 'private';
    }
    if ($method->isStatic()) {
        $parts[] = 'static';
    }
    $parts[] = 'function';
    $parts[] = $method->getName().'(';

    $params = [];
    foreach ($method->getParameters() as $param) {
        $p = '';
        if ($param->hasType()) {
            $p .= $param->getType().' ';
        }
        $p .= '$'.$param->getName();
        if ($param->isOptional() && ! $param->isVariadic()) {
            try {
                $default = $param->getDefaultValue();
                $p .= ' = '.json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (ReflectionException) {
                $p .= ' = <default>';
            }
        }
        $params[] = $p;
    }

    $parts[count($parts) - 1] .= implode(', ', $params).')';

    if ($method->hasReturnType()) {
        $parts[] = ': '.$method->getReturnType();
    }

    return implode(' ', $parts);
}

/**
 * Build a canonical signature for a property.
 */
function propertySignature(ReflectionProperty $prop): string
{
    $parts = [];
    if ($prop->isPublic()) {
        $parts[] = 'public';
    }
    if ($prop->isReadOnly()) {
        $parts[] = 'readonly';
    }
    if ($prop->isStatic()) {
        $parts[] = 'static';
    }
    if ($prop->hasType()) {
        $parts[] = (string) $prop->getType();
    }
    $parts[] = '$'.$prop->getName();

    return implode(' ', $parts);
}

$snapshot = [];

foreach ($classes as $className) {
    $ref = new ReflectionClass($className);
    $classDoc = $ref->getDocComment() ?: null;
    $classIsApi = isApi($classDoc);
    $classIsInternal = isInternal($classDoc);

    $entry = [
        'kind' => $ref->isAbstract() ? 'abstract_class' : 'class',
        'api' => $classIsApi,
        'internal' => $classIsInternal,
        'methods' => [],
        'properties' => [],
    ];

    // Public methods (own + inherited from same namespace? No — only declared in this class)
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));

    foreach ($methods as $method) {
        // Skip methods declared in parent classes outside HaoCode\Sdk
        if ($method->getDeclaringClass()->getName() !== $className) {
            continue;
        }
        $doc = $method->getDocComment() ?: null;
        $entry['methods'][$method->getName()] = [
            'signature' => methodSignature($method),
            'api' => isApi($doc),
            'internal' => isInternal($doc),
        ];
    }

    // Public properties (readonly constructor-promoted included)
    $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
    usort($props, fn ($a, $b) => strcmp($a->getName(), $b->getName()));

    foreach ($props as $prop) {
        if ($prop->getDeclaringClass()->getName() !== $className) {
            continue;
        }
        $doc = $prop->getDocComment() ?: null;
        $entry['properties'][$prop->getName()] = [
            'signature' => propertySignature($prop),
            'api' => isApi($doc),
            'internal' => isInternal($doc),
        ];
    }

    $snapshot[$className] = $entry;
}

ksort($snapshot);

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if ($mode === '--write') {
    $dir = dirname(SNAPSHOT_PATH);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(SNAPSHOT_PATH, $json);
    echo 'Snapshot written to '.SNAPSHOT_PATH."\n";
    exit(0);
}

// --verify
if (! file_exists(SNAPSHOT_PATH)) {
    fwrite(STDERR, 'Snapshot not found: '.SNAPSHOT_PATH."\n");
    fwrite(STDERR, "Run: php scripts/sdk-bc-check.php --write\n");
    exit(1);
}

$existing = file_get_contents(SNAPSHOT_PATH);
$existingDecoded = json_decode($existing, true);
$currentDecoded = json_decode($json, true);

if ($existingDecoded === $currentDecoded) {
    echo "OK: public API matches snapshot.\n";
    exit(0);
}

// Produce a human-readable diff
$existingLines = explode("\n", json_encode($existingDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$currentLines = explode("\n", json_encode($currentDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$existingFile = sys_get_temp_dir().'/sdk-bc-existing.json';
$currentFile = sys_get_temp_dir().'/sdk-bc-current.json';
file_put_contents($existingFile, implode("\n", $existingLines));
file_put_contents($currentFile, implode("\n", $currentLines));

fwrite(STDERR, "FAIL: public API has changed from snapshot.\n");
$diff = shell_exec("diff -u $existingFile $currentFile");
fwrite(STDERR, $diff ?? '(diff unavailable)');
exit(1);

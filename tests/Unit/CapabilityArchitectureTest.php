<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CapabilityArchitectureTest extends TestCase
{
    public function test_provider_capability_package_does_not_depend_on_sdk_layer(): void
    {
        $directory = dirname(__DIR__, 2).'/app/Services/Api/Capability';
        $paths = glob($directory.'/*.php') ?: [];

        $this->assertNotSame([], $paths);
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $sdkReferences = [];
            foreach (token_get_all($source) as $token) {
                if (! is_array($token)
                    || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $name = ltrim($token[1], '\\');
                if ($name === 'HaoCode\\Sdk' || str_starts_with($name, 'HaoCode\\Sdk\\')) {
                    $sdkReferences[] = $name;
                }
            }
            $this->assertSame(
                [],
                array_values(array_unique($sdkReferences)),
                basename($path).' must stay independent from the SDK layer.',
            );

            $className = 'HaoCode\\Services\\Api\\Capability\\'.basename($path, '.php');
            $reflection = new \ReflectionClass($className);
            $referencedTypes = [];

            foreach ($reflection->getProperties() as $property) {
                $referencedTypes = array_merge($referencedTypes, $this->typeNames($property->getType()));
            }
            foreach ($reflection->getMethods() as $method) {
                $referencedTypes = array_merge($referencedTypes, $this->typeNames($method->getReturnType()));
                foreach ($method->getParameters() as $parameter) {
                    $referencedTypes = array_merge($referencedTypes, $this->typeNames($parameter->getType()));
                }
            }

            foreach (array_unique($referencedTypes) as $type) {
                $this->assertFalse(
                    str_starts_with(ltrim($type, '\\'), 'HaoCode\\Sdk\\'),
                    $className.' exposes an SDK-layer dependency through '.$type.'.',
                );
            }
        }
    }

    /** @return list<string> */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $nested) {
                $names = array_merge($names, $this->typeNames($nested));
            }

            return $names;
        }

        return [];
    }
}

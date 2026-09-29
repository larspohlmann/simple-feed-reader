<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

/** The class types of the constructor arguments the container would have to supply (#1202). */
final class SuppliedConstructorTypes
{
    private const string DEPENDENCY_INJECTION_ATTRIBUTES = 'Symfony\\Component\\DependencyInjection\\Attribute\\';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function of(ClassReflection $reflection): array
    {
        $constructor = $reflection->getNativeReflection()->getConstructor();
        if (null === $constructor) {
            return [];
        }
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() || self::isConfiguredByAttribute($parameter)) {
                continue;
            }
            $types = [...$types, ...self::classNamesOf($parameter->getType())];
        }

        return $types;
    }

    private static function isConfiguredByAttribute(\ReflectionParameter $parameter): bool
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if (ClassNameReferences::isInAnyOf($attribute->getName(), [self::DEPENDENCY_INJECTION_ATTRIBUTES])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function classNamesOf(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...self::classNamesOf($member)];
            }

            return $names;
        }

        return [];
    }
}

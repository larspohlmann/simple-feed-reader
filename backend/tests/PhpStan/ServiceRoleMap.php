<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

final readonly class ServiceRoleMap
{
    private const array VALUE_NAMESPACES = [
        'App\\Entity\\', 'App\\Enum\\', 'App\\Pagination\\', 'App\\Doctrine\\', 'Dom\\',
    ];

    private const array VALUE_CLASSES = ['DateTimeInterface', 'DateTimeZone', 'DateInterval', 'Throwable', 'DOMNode'];

    /**
     * @param array<string, ServiceRoleClass> $classes
     * @param array<string, true> $builtPerCall
     * @param array<string, true> $builtInConstructor
     * @param list<UnresolvedServiceRoleClass> $unresolvedClasses
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private array $classes,
        private array $builtPerCall,
        private array $builtInConstructor,
        private array $unresolvedClasses,
    ) {
    }

    public static function fromCollected(ReflectionProvider $reflectionProvider, CollectedDataNode $node): self
    {
        return self::fromCollectedData(
            $reflectionProvider,
            $node->get(ServiceRoleClassCollector::class),
            $node->get(ServiceRoleInstantiationCollector::class),
        );
    }

    /**
     * @param array<string, list<array{string, int, list<string>}>> $collectedClasses
     * @param array<string, list<array{string, bool}>> $collectedInstantiations
     */
    public static function fromCollectedData(
        ReflectionProvider $reflectionProvider,
        array $collectedClasses,
        array $collectedInstantiations,
    ): self {
        $classes = [];
        $unresolved = [];
        foreach ($collectedClasses as $file => $collected) {
            foreach ($collected as [$name, $line, $dtoReferences]) {
                if (!$reflectionProvider->hasClass($name)) {
                    $unresolved[$name] = new UnresolvedServiceRoleClass($name, $file, $line);

                    continue;
                }
                $reflection = $reflectionProvider->getClass($name);
                $classes[$name] = new ServiceRoleClass($reflection, $file, $line, $dtoReferences);
            }
        }
        $perCall = [];
        $inConstructor = [];
        foreach ($collectedInstantiations as $instantiations) {
            foreach ($instantiations as [$name, $isPerCall]) {
                if ($isPerCall) {
                    $perCall[$name] = true;
                } else {
                    $inConstructor[$name] = true;
                }
            }
        }
        ksort($classes);
        ksort($unresolved);

        return new self($reflectionProvider, $classes, $perCall, $inConstructor, array_values($unresolved));
    }

    /** @return list<UnresolvedServiceRoleClass> */
    public function unresolvedClasses(): array
    {
        return $this->unresolvedClasses;
    }

    /** @return list<ServiceRoleClass> */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    public function classFor(string $type): ?ServiceRoleClass
    {
        return $this->classes[$type] ?? null;
    }

    /** @return list<ServiceRoleClass> */
    public function classesIn(string $namespace): array
    {
        return array_values(array_filter(
            $this->classes,
            static fn (ServiceRoleClass $class): bool => $class->namespace() === $namespace,
        ));
    }

    /** @return list<ServiceRoleClass> */
    public function classesUnder(string $namespace): array
    {
        return array_values(array_filter(
            $this->classes,
            static fn (ServiceRoleClass $class): bool => ClassNameReferences::isInAnyOf(
                $class->namespace(),
                [$namespace . '\\'],
            ),
        ));
    }

    /** Built for one invocation: by `new` outside a constructor, or by a constructor while holding mutable state. */
    public function isPerCall(ServiceRoleClass $class): bool
    {
        return isset($this->builtPerCall[$class->name()])
            || (isset($this->builtInConstructor[$class->name()]) && $class->isStateful());
    }

    /** Whether a constructor argument of this type is a service rather than a value. */
    public function isCollaborator(string $type): bool
    {
        if (!$this->reflectionProvider->hasClass($type)) {
            return false;
        }
        $reflection = $this->reflectionProvider->getClass($type);
        if ($reflection->isEnum() || self::isValue($reflection)) {
            return false;
        }
        if (!ClassNameReferences::isInAnyOf($type, ['App\\'])) {
            return true;
        }
        if ($reflection->isInterface()) {
            return ServiceRoleNames::MODEL !== ServiceRoleNames::roleOfClass($type);
        }
        $class = $this->classFor($type);
        $isPerCall = null === $class ? isset($this->builtPerCall[$type]) : $this->isPerCall($class);

        return !$isPerCall && ServiceRoleClass::declaresPublicInstanceMethod($reflection);
    }

    /** @return list<string> the interfaces of its own module it implements, outside Factory/, Model/, Exception/ */
    public function sameModuleInterfaces(ServiceRoleClass $class): array
    {
        $module = ServiceRoleNames::moduleOf($class->name());
        $interfaces = array_filter(
            $class->interfaceNames(),
            static fn (string $interface): bool => ServiceRoleNames::moduleOf($interface) === $module
                && ServiceRoleNames::isServiceOrHttp($interface)
                && null === ServiceRoleNames::roleOfClass($interface),
        );
        sort($interfaces);

        return $interfaces;
    }

    /** The folder named after the interface; a folder that holds nothing but its family is renamed after it. */
    public function interfaceFolder(string $interface): string
    {
        $namespace = ServiceRoleNames::namespaceOf($interface);
        $base = ServiceRoleNames::interfaceBaseOf($interface);
        if (ServiceRoleNames::shortNameOf($namespace) === $base) {
            return $namespace;
        }
        if ($this->holdsOnlyTheFamilyOf($interface)) {
            return ServiceRoleNames::namespaceOf($namespace) . '\\' . $base;
        }

        return $namespace . '\\' . $base;
    }

    public function folderInterfaceOf(string $namespace): ?string
    {
        $interface = $namespace . '\\' . ServiceRoleNames::shortNameOf($namespace) . 'Interface';

        return isset($this->classes[$interface]) && $this->classes[$interface]->isInterface() ? $interface : null;
    }

    private function holdsOnlyTheFamilyOf(string $interface): bool
    {
        foreach ($this->classesIn(ServiceRoleNames::namespaceOf($interface)) as $member) {
            if (!$member->isOfFamily($interface)) {
                return false;
            }
        }

        return true;
    }

    private static function isValue(ClassReflection $reflection): bool
    {
        if (ClassNameReferences::isInAnyOf($reflection->getName(), self::VALUE_NAMESPACES)) {
            return true;
        }
        foreach (self::VALUE_CLASSES as $value) {
            if ($reflection->getName() === $value || $reflection->isSubclassOf($value)) {
                return true;
            }
        }

        return false;
    }
}

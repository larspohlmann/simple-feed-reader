<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use App\DependencyInjection\ProcessLifetimeState;
use PHPStan\Reflection\ClassReflection;

final readonly class ServiceRoleClass
{
    private const array RESET_CONTRACTS = [
        'Symfony\\Contracts\\Service\\ResetInterface',
        'Monolog\\ResettableInterface',
    ];

    private const string LISTENER_ATTRIBUTE = 'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener';

    private const string SUBSCRIBER_INTERFACE = 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface';

    private const string DEPENDENCY_INJECTION_ATTRIBUTES = 'Symfony\\Component\\DependencyInjection\\Attribute\\';

    /** @param list<string> $dtoReferences */
    public function __construct(
        public ClassReflection $reflection,
        public string $file,
        public int $line,
        public array $dtoReferences,
    ) {
    }

    /** In App\EventListener, or declared a listener by attribute on the class or a method, or a subscriber. */
    public static function isEventListener(ClassReflection $reflection): bool
    {
        if (
            ServiceRoleNames::isListener($reflection->getName())
            || $reflection->implementsInterface(self::SUBSCRIBER_INTERFACE)
        ) {
            return true;
        }
        $native = $reflection->getNativeReflection();
        if ([] !== $native->getAttributes(self::LISTENER_ATTRIBUTE)) {
            return true;
        }
        foreach ($native->getMethods() as $method) {
            if ([] !== $method->getAttributes(self::LISTENER_ATTRIBUTE)) {
                return true;
            }
        }

        return false;
    }

    public function name(): string
    {
        return $this->reflection->getName();
    }

    public function namespace(): string
    {
        return ServiceRoleNames::namespaceOf($this->name());
    }

    public function shortName(): string
    {
        return ServiceRoleNames::shortNameOf($this->name());
    }

    public function role(): ?string
    {
        return ServiceRoleNames::roleOf($this->namespace());
    }

    public function area(): string
    {
        return ServiceRoleNames::areaOf($this->namespace());
    }

    public function isInterface(): bool
    {
        return $this->reflection->isInterface();
    }

    public function isEnum(): bool
    {
        return $this->reflection->isEnum();
    }

    public function isPlainClass(): bool
    {
        return $this->reflection->isClass() && !$this->reflection->isEnum();
    }

    public function isAbstract(): bool
    {
        return $this->reflection->isAbstract();
    }

    public function isFinalReadonly(): bool
    {
        return $this->reflection->isFinalByKeyword() && $this->reflection->isReadOnly();
    }

    public function mayBeReadonly(): bool
    {
        $parent = $this->reflection->getParentClass();

        return null === $parent || $parent->isReadOnly();
    }

    /** Pure functions over values: no instance method, no instance property, no constructor argument. */
    public function isStaticOnly(): bool
    {
        if (!$this->isPlainClass() || $this->isAbstract()) {
            return false;
        }
        $native = $this->reflection->getNativeReflection();
        foreach ($native->getMethods() as $method) {
            $isOwn = $method->getDeclaringClass()->getName() === $this->name();
            if ($isOwn && !$method->isStatic() && !$method->isConstructor()) {
                return false;
            }
        }
        $constructor = $native->getConstructor();

        return [] === $this->instanceProperties()
            && (null === $constructor || 0 === $constructor->getNumberOfParameters());
    }

    public function declaresStaticMethod(): bool
    {
        foreach ($this->reflection->getNativeReflection()->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $this->name()) {
                return true;
            }
        }

        return false;
    }

    public static function declaresPublicInstanceMethod(ClassReflection $reflection): bool
    {
        foreach ($reflection->getNativeReflection()->getMethods() as $method) {
            if (!$method->isStatic() && !$method->isConstructor() && $method->isPublic()) {
                return true;
            }
        }

        return false;
    }

    public function hasPrivateConstructor(): bool
    {
        $constructor = $this->reflection->getNativeReflection()->getConstructor();

        return null !== $constructor && $constructor->isPrivate();
    }

    /** @return list<string> the names of the class's own static properties */
    public function staticProperties(): array
    {
        $static = [];
        foreach ($this->reflection->getNativeReflection()->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getDeclaringClass()->getName() === $this->name()) {
                $static[] = $property->getName();
            }
        }

        return $static;
    }

    /** @return list<string> the names of the class's own properties that are neither static nor readonly */
    public function mutableProperties(): array
    {
        $mutable = [];
        foreach ($this->instanceProperties() as $property) {
            if (!$property->isReadOnly()) {
                $mutable[] = $property->getName();
            }
        }

        return $mutable;
    }

    public function isStateful(): bool
    {
        return [] !== $this->mutableProperties();
    }

    public function resetsItsState(): bool
    {
        foreach (self::RESET_CONTRACTS as $contract) {
            if ($this->reflection->implementsInterface($contract)) {
                return true;
            }
        }

        return [] !== $this->reflection->getNativeReflection()->getAttributes(ProcessLifetimeState::class);
    }

    /** @return list<string> the class types of the constructor arguments the container would have to supply */
    public function suppliedConstructorTypes(): array
    {
        $constructor = $this->reflection->getNativeReflection()->getConstructor();
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

    /** The interface itself, or a class or interface that implements or extends it. */
    public function isOfFamily(string $interface): bool
    {
        return $this->name() === $interface || $this->reflection->implementsInterface($interface);
    }

    /** @return list<string> every interface the class implements, through its parents too */
    public function interfaceNames(): array
    {
        return array_values(array_map(
            static fn (ClassReflection $interface): string => $interface->getName(),
            $this->reflection->getInterfaces(),
        ));
    }

    public function invokedMessage(): ?string
    {
        if (!$this->reflection->hasNativeMethod('__invoke')) {
            return null;
        }
        $parameters = $this->reflection->getNativeMethod('__invoke')->getOnlyVariant()->getParameters();
        $classes = [] === $parameters ? [] : $parameters[0]->getType()->getObjectClassNames();

        return $classes[0] ?? null;
    }

    /** @return list<\ReflectionProperty> */
    private function instanceProperties(): array
    {
        $own = [];
        foreach ($this->reflection->getNativeReflection()->getProperties() as $property) {
            if (!$property->isStatic() && $property->getDeclaringClass()->getName() === $this->name()) {
                $own[] = $property;
            }
        }

        return $own;
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

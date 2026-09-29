<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use App\DependencyInjection\ProcessLifetimeState;
use PHPStan\Reflection\ClassReflection;

/** What a class's own declaration says about its state and how it is built (#1202). */
final readonly class ClassShape
{
    private const array RESET_CONTRACTS = [
        'Symfony\\Contracts\\Service\\ResetInterface',
        'Monolog\\ResettableInterface',
    ];

    public function __construct(private ClassReflection $reflection)
    {
    }

    public function isAbstract(): bool
    {
        return $this->reflection->isAbstract();
    }

    public function isFinal(): bool
    {
        return $this->reflection->isFinalByKeyword();
    }

    public function isReadonly(): bool
    {
        return $this->reflection->isReadOnly();
    }

    public function isFinalReadonly(): bool
    {
        return $this->isFinal() && $this->isReadonly();
    }

    public function mayBeReadonly(): bool
    {
        $parent = $this->reflection->getParentClass();

        return null === $parent || $parent->isReadOnly();
    }

    /** Pure functions over values: no instance method, no instance property, no constructor argument. */
    public function isStaticOnly(): bool
    {
        if (!$this->reflection->isClass() || $this->reflection->isEnum() || $this->isAbstract()) {
            return false;
        }
        $constructor = $this->reflection->getNativeReflection()->getConstructor();

        return !$this->declaresInstanceMethod()
            && [] === $this->instanceProperties()
            && (null === $constructor || 0 === $constructor->getNumberOfParameters());
    }

    public function declaresStaticMethod(): bool
    {
        foreach ($this->reflection->getNativeReflection()->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($this->isOwn($method)) {
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
            if ($this->isOwn($property)) {
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

    private function declaresInstanceMethod(): bool
    {
        foreach ($this->reflection->getNativeReflection()->getMethods() as $method) {
            if ($this->isOwn($method) && !$method->isStatic() && !$method->isConstructor()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<\ReflectionProperty> */
    private function instanceProperties(): array
    {
        $own = [];
        foreach ($this->reflection->getNativeReflection()->getProperties() as $property) {
            if (!$property->isStatic() && $this->isOwn($property)) {
                $own[] = $property;
            }
        }

        return $own;
    }

    private function isOwn(\ReflectionMethod|\ReflectionProperty $member): bool
    {
        return $member->getDeclaringClass()->getName() === $this->reflection->getName();
    }
}

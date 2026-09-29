<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

final readonly class ServiceRoleClass
{
    public ClassShape $shape;

    /** @param list<string> $dtoReferences */
    public function __construct(
        public ClassReflection $reflection,
        public string $file,
        public int $line,
        public array $dtoReferences,
    ) {
        $this->shape = new ClassShape($reflection);
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
        return ServiceRoleNames::roleOfClass($this->name());
    }

    public function area(): string
    {
        return ServiceRoleNames::areaOf($this->namespace());
    }

    public function movedTo(string $namespace): string
    {
        return $namespace . '\\' . $this->shortName();
    }

    public function roleHome(string $role): string
    {
        return $this->movedTo($this->area() . '\\' . $role);
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
}

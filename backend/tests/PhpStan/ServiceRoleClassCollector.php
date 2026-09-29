<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Type\Type;

/** @implements Collector<InClassNode, array{string, int, list<string>}> */
final readonly class ServiceRoleClassCollector implements Collector
{
    public function __construct(private NodeFinder $finder)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     *
     * @return array{string, int, list<string>}|null the class, its line, and the Dto classes a model names
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $reflection = $node->getClassReflection();
        $name = $reflection->getName();
        if (!ServiceRoleNames::isServiceOrHttp($name) && !self::isProductionListener($reflection)) {
            return null;
        }

        return [$name, $node->getOriginalNode()->getStartLine(), $this->modelDtoReferencesIn($node)];
    }

    private static function isProductionListener(ClassReflection $reflection): bool
    {
        return ServiceRoleNames::isProductionNamespace(ServiceRoleNames::namespaceOf($reflection->getName()))
            && EventListenerDeclarations::isEventListener($reflection);
    }

    /** @return list<string> */
    private function modelDtoReferencesIn(InClassNode $node): array
    {
        $reflection = $node->getClassReflection();
        if (ServiceRoleNames::MODEL !== ServiceRoleNames::roleOfClass($reflection->getName())) {
            return [];
        }
        $references = array_filter(
            [...$this->namesIn($node), ...self::docblockClassesOf($reflection)],
            static fn (string $name): bool => str_contains($name, '\\Dto\\'),
        );

        return array_values(array_unique($references));
    }

    /** @return list<string> */
    private function namesIn(InClassNode $node): array
    {
        return array_values(array_map(
            static fn (Name $name): string => $name->toString(),
            $this->finder->findInstanceOf($node->getOriginalNode()->stmts, Name::class),
        ));
    }

    /** @return list<string> the classes the PHPDoc types of its own properties, parameters and returns name */
    private static function docblockClassesOf(ClassReflection $reflection): array
    {
        $types = [];
        $native = $reflection->getNativeReflection();
        foreach ($native->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
                $types[] = $reflection->getNativeProperty($property->getName())->getPhpDocType();
            }
        }
        foreach ($native->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $reflection->getName()) {
                $types = [...$types, ...self::phpDocTypesOf($reflection->getNativeMethod($method->getName()))];
            }
        }

        return array_merge(...array_map(static fn (Type $type): array => $type->getReferencedClasses(), $types));
    }

    /** @return list<Type> */
    private static function phpDocTypesOf(ExtendedMethodReflection $method): array
    {
        $types = [];
        foreach ($method->getVariants() as $variant) {
            $types[] = $variant->getPhpDocReturnType();
            foreach ($variant->getParameters() as $parameter) {
                $types[] = $parameter->getPhpDocType();
            }
        }

        return $types;
    }
}

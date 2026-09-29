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

    /** @return array{string, int, list<string>}|null the class, its line, and the Dto classes its body names */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $reflection = $node->getClassReflection();
        $name = $reflection->getName();
        if (!ServiceRoleNames::isServiceOrHttp($name) && !self::isProductionListener($reflection)) {
            return null;
        }

        return [$name, $node->getOriginalNode()->getStartLine(), $this->dtoReferencesIn($node)];
    }

    private static function isProductionListener(ClassReflection $reflection): bool
    {
        return ServiceRoleNames::isProductionNamespace(ServiceRoleNames::namespaceOf($reflection->getName()))
            && ServiceRoleClass::isEventListener($reflection);
    }

    /** @return list<string> */
    private function dtoReferencesIn(InClassNode $node): array
    {
        $references = [];
        foreach ($this->finder->findInstanceOf($node->getOriginalNode()->stmts, Name::class) as $name) {
            if (str_contains($name->toString(), '\\Dto\\')) {
                $references[] = $name->toString();
            }
        }

        return array_values(array_unique($references));
    }
}

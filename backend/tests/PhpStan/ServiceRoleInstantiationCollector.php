<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/** @implements Collector<New_, array{string, bool}> */
final readonly class ServiceRoleInstantiationCollector implements Collector
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    /** @return array{string, bool}|null the class built, and whether it is built outside a constructor */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->class instanceof Name || !ServiceRoleNames::isProductionNamespace($scope->getNamespace() ?? '')) {
            return null;
        }

        return [$scope->resolveName($node->class), !self::isInConstructor($node, $scope)];
    }

    /** A parameter's default is analysed outside its method, so the constructor's lines tell whether it is one of its. */
    private static function isInConstructor(New_ $node, Scope $scope): bool
    {
        if (null !== $scope->getFunction() || !$scope->isInClass()) {
            return '__construct' === $scope->getFunctionName();
        }
        $constructor = $scope->getClassReflection()->getNativeReflection()->getConstructor();

        return null !== $constructor
            && $node->getStartLine() >= $constructor->getStartLine()
            && $node->getStartLine() <= $constructor->getEndLine();
    }
}

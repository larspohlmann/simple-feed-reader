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

        return [$scope->resolveName($node->class), '__construct' !== $scope->getFunctionName()];
    }
}

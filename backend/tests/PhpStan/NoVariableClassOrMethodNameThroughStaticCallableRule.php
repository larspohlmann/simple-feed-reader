<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\Rule;

/** @implements Rule<StaticMethodCallableNode> */
final readonly class NoVariableClassOrMethodNameThroughStaticCallableRule implements Rule
{
    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->getClass() instanceof Expr && !$node->getName() instanceof Expr) {
            return [];
        }

        return VariableClassOrMethodNameReport::inApplicationCode($scope);
    }
}

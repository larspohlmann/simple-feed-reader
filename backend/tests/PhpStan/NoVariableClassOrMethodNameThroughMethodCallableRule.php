<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Rules\Rule;

/** @implements Rule<MethodCallableNode> */
final readonly class NoVariableClassOrMethodNameThroughMethodCallableRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->getName() instanceof Expr) {
            return [];
        }

        return VariableClassOrMethodNameReport::inApplicationCode($scope);
    }
}

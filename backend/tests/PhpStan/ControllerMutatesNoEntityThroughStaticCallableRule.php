<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticMethodCallableNode>
 */
final readonly class ControllerMutatesNoEntityThroughStaticCallableRule implements Rule
{
    public function __construct(private ControllerEntityUse $entityUse)
    {
    }

    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->entityUse->isInController($scope)) {
            return [];
        }

        return $this->entityUse->staticCallErrors($node->getClass(), $node->getName(), $scope);
    }
}

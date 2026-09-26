<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<MethodCallableNode>
 */
final readonly class ControllerMutatesNoEntityThroughMethodCallableRule implements Rule
{
    public function __construct(private ControllerEntityUse $entityUse)
    {
    }

    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->entityUse->isInController($scope)) {
            return [];
        }

        return $this->entityUse->methodCallErrors($node->getVar(), $node->getName(), $scope);
    }
}

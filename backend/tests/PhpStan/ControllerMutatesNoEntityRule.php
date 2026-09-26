<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Thin-controller rule, expression half (#1157). A ?-> call also arrives as a MethodCall (F1); a first-class callable
 * arrives only as a *CallableNode, which the two ControllerMutatesNoEntityThrough*CallableRule siblings take.
 *
 * @implements Rule<CallLike>
 */
final readonly class ControllerMutatesNoEntityRule implements Rule
{
    public function __construct(private ControllerEntityUse $entityUse)
    {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->entityUse->isInController($scope)) {
            return [];
        }

        return match (true) {
            $node instanceof New_ => $this->entityUse->constructionErrors($node->class, $scope),
            $node instanceof StaticCall => $this->entityUse->staticCallErrors($node->class, $node->name, $scope),
            $node instanceof MethodCall => $this->entityUse->methodCallErrors($node->var, $node->name, $scope),
            default => [],
        };
    }
}

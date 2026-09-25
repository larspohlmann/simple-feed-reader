<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use App\Entity\PersistedId;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Cast\Int_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node\Expr> */
final readonly class EntityIdCoercionRule implements Rule
{
    public const string MESSAGE = "Read a persisted entity's id with requireId(); "
        . 'casting or defaulting getId() hides an unsaved entity (#1165); add `use PersistedId` '
        . 'to an entity that lacks requireId().';

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $coerced = match (true) {
            $node instanceof Int_ => $node->expr,
            $node instanceof Coalesce => $node->left,
            $node instanceof Ternary && null === $node->if => $node->cond,
            default => null,
        };

        if (!$this->isGetIdCall($coerced) || !$this->readsAnEntityId($coerced, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier('simpleFeedReader.entityIdCoercion')
                ->build(),
        ];
    }

    /** @phpstan-assert-if-true MethodCall|NullsafeMethodCall $coerced */
    private function isGetIdCall(?Node $coerced): bool
    {
        return $coerced instanceof MethodCall || $coerced instanceof NullsafeMethodCall;
    }

    private function readsAnEntityId(MethodCall|NullsafeMethodCall $call, Scope $scope): bool
    {
        if (!$call->name instanceof Identifier || 'getId' !== $call->name->toString()) {
            return false;
        }
        if ($scope->isInTrait() && PersistedId::class === $scope->getTraitReflection()->getName()) {
            return false;
        }

        foreach ($scope->getType($call->var)->getObjectClassNames() as $className) {
            if (str_starts_with($className, 'App\\Entity\\')) {
                return true;
            }
        }

        return false;
    }
}

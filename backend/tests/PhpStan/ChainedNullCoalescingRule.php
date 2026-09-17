<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<FileNode> */
final readonly class ChainedNullCoalescingRule implements Rule
{
    private const int MAX_OPERATORS = 2;

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->errorsIn($node->getNodes());
    }

    /**
     * @param array<mixed>|Node $value
     *
     * @return list<IdentifierRuleError>
     */
    private function errorsIn(array|Node $value, bool $parentIsCoalesce = false): array
    {
        if (\is_array($value)) {
            $errors = [];
            foreach ($value as $node) {
                if (!$node instanceof Node) {
                    continue;
                }
                $errors = [...$errors, ...$this->errorsIn($node)];
            }

            return $errors;
        }

        $errors = [];
        if (
            $value instanceof Coalesce
            && !$parentIsCoalesce
            && $this->operatorCount($value) > self::MAX_OPERATORS
        ) {
            $errors[] = RuleErrorBuilder::message(
                'A null-coalescing chain can contain at most two operators. '
                . 'Extract the fallback selection into a named method or explicit control flow.',
            )
                ->identifier('simpleFeedReader.chainedNullCoalescing')
                ->line($value->getStartLine())
                ->build();
        }

        foreach ($value->getSubNodeNames() as $subNodeName) {
            $subNode = $value->{$subNodeName};
            if ($subNode instanceof Node || \is_array($subNode)) {
                $errors = [...$errors, ...$this->errorsIn($subNode, $value instanceof Coalesce)];
            }
        }

        return $errors;
    }

    private function operatorCount(Coalesce $node): int
    {
        $leftCount = $node->left instanceof Coalesce ? $this->operatorCount($node->left) : 0;
        $rightCount = $node->right instanceof Coalesce ? $this->operatorCount($node->right) : 0;

        return 1 + $leftCount + $rightCount;
    }
}

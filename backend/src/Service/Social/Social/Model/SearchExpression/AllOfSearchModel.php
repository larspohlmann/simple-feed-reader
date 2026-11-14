<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

final readonly class AllOfSearchModel implements SearchExpressionModelInterface
{
    /** @param list<SearchExpressionModelInterface> $operands */
    public function __construct(private array $operands)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        return array_all(
            $this->operands,
            static fn (SearchExpressionModelInterface $operand): bool => $operand->matches($post),
        );
    }

    public function isFullyChecked(): bool
    {
        return array_all(
            $this->operands,
            static fn (SearchExpressionModelInterface $operand): bool => $operand->isFullyChecked(),
        );
    }
}

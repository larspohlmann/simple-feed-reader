<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model\SearchExpression;

use App\Service\Social\Social\Model\SearchedPostModel;

final readonly class AnyOfSearchModel implements SearchExpressionModelInterface
{
    /** @param list<SearchExpressionModelInterface> $operands */
    public function __construct(private array $operands)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        return array_any(
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

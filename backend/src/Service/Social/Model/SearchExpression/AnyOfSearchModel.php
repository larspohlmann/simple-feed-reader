<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

final readonly class AnyOfSearchModel implements SearchExpressionModelInterface
{
    /** @param list<SearchExpressionModelInterface> $operands */
    public function __construct(private array $operands)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        foreach ($this->operands as $operand) {
            if ($operand->matches($post)) {
                return true;
            }
        }

        return false;
    }
}

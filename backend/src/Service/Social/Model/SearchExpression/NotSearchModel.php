<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

final readonly class NotSearchModel implements SearchExpressionModelInterface
{
    public function __construct(private SearchExpressionModelInterface $operand)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        return !$this->operand->matches($post);
    }

    public function isFullyChecked(): bool
    {
        return $this->operand->isFullyChecked();
    }
}

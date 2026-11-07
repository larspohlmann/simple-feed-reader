<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

/** A check that left out a filter it cannot read: it may keep too much, so its negation must not narrow. */
final readonly class PartlyCheckedSearchModel implements SearchExpressionModelInterface
{
    public function __construct(private SearchExpressionModelInterface $checkedPart)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        return $this->checkedPart->matches($post);
    }
}

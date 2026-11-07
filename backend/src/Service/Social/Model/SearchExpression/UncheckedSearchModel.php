<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

/** What the reader cannot check in a post's text, a network-side filter such as `lang:de`: it never narrows. */
final readonly class UncheckedSearchModel implements SearchExpressionModelInterface
{
    public function matches(SearchedPostModel $post): bool
    {
        return true;
    }
}

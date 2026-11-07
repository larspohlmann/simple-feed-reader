<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

/**
 * A parsed search query, judged against a post: each term may match in any one of its texts.
 *
 * @phpstan-sealed SearchTermModel|AuthorSearchModel|AllOfSearchModel|AnyOfSearchModel|NotSearchModel|UncheckedSearchModel|PartlyCheckedSearchModel
 */
interface SearchExpressionModelInterface
{
    public function matches(SearchedPostModel $post): bool;
}

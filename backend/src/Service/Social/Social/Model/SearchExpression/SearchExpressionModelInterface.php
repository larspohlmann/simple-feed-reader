<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model\SearchExpression;

use App\Service\Social\Social\Model\SearchedPostModel;

/**
 * A parsed search query, judged against a post: each term may match in any one of its texts.
 *
 * @phpstan-sealed SearchTermModel|AuthorSearchModel|AllOfSearchModel|AnyOfSearchModel|NotSearchModel|UncheckedSearchModel
 */
interface SearchExpressionModelInterface
{
    public function matches(SearchedPostModel $post): bool;

    /** False when it leaves out a filter the reader cannot read: it may keep too much, so its negation must not narrow. */
    public function isFullyChecked(): bool;
}

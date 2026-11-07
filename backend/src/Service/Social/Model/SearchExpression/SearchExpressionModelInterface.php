<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

/**
 * A parsed search query, judged against a post's lower-cased texts: each term may match in any one of them.
 *
 * @phpstan-sealed SearchTermModel|AllOfSearchModel|AnyOfSearchModel|NotSearchModel|UncheckedSearchModel
 */
interface SearchExpressionModelInterface
{
    public function matches(string ...$lowerCasedTexts): bool;
}

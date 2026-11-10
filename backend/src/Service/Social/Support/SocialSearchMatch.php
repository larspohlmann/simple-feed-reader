<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\SearchedPostModel;
use App\Service\Social\Pass\SearchQueryParser;

/** The reader's own reading of a search: networks also match link cards, alt text and quotes the reader never shows. */
final class SocialSearchMatch
{
    /** @return \Closure(SearchedPostModel): bool whether a post satisfies the query, a phrase never spanning two texts */
    public static function matcher(string $query): \Closure
    {
        return new SearchQueryParser(SearchQueryTokens::of($query))->expression()->matches(...);
    }

    private function __construct()
    {
    }
}

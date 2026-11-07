<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Pass\SearchQueryParser;

/** The reader's own reading of a search: networks also match link cards, alt text and quotes the reader never shows. */
final class SocialSearchMatch
{
    /** @return \Closure(string...): bool whether a post's texts satisfy the query, a phrase never spanning two */
    public static function matcher(string $query): \Closure
    {
        $expression = (new SearchQueryParser($query))->expression();

        return static fn (string ...$texts): bool => $expression->matches(...array_map(mb_strtolower(...), $texts));
    }

    private function __construct()
    {
    }
}

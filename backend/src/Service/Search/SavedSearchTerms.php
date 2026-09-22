<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;

/**
 * A saved search's stored shape — a bare term plus two mode columns — read as
 * the SearchTerms the search domain runs on. One mapping, so every matcher
 * reads a saved search the same way.
 */
final readonly class SavedSearchTerms
{
    public static function of(SavedSearch $savedSearch): SearchTerms
    {
        return SearchTerms::fromTermAndMode(
            $savedSearch->getTerm(),
            SearchMode::fromFlags($savedSearch->isWholeWord(), $savedSearch->isPhrase()),
        );
    }

    public static function termOf(SavedSearch $savedSearch): SavedSearchTerm
    {
        return new SavedSearchTerm((int) $savedSearch->getId(), self::of($savedSearch));
    }
}

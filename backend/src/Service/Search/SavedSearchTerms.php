<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;

/**
 * A saved search's stored shape — a bare term plus two mode columns — read as
 * the SearchTerms the search domain runs on. One mapping, so the badge scan and
 * the combined list can never disagree about what a saved search matches.
 */
final readonly class SavedSearchTerms
{
    public function __construct(private SavedSearchRepository $savedSearches)
    {
    }

    public static function of(SavedSearch $savedSearch): SearchTerms
    {
        return SearchTerms::fromTermAndMode(
            $savedSearch->getTerm(),
            SearchMode::fromFlags($savedSearch->isWholeWord(), $savedSearch->isPhrase()),
        );
    }

    /** @return list<SavedSearchTerm> the user's saved searches, in sidebar order */
    public function forUser(int $userId): array
    {
        return array_map(
            static fn (SavedSearch $search): SavedSearchTerm => new SavedSearchTerm(
                (int) $search->getId(),
                self::of($search),
            ),
            $this->savedSearches->findForUser($userId),
        );
    }
}

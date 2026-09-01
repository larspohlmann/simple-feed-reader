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

    /** @return list<SearchTerms> */
    public function forUser(int $userId): array
    {
        return $this->forUserWithIds($userId)->terms;
    }

    /**
     * The same read as forUser(), plus each search's own id, both in the
     * sidebar's order and learned from one query — so a caller that needs to
     * name which search matched can never see the ids and the terms drift out
     * of step with each other.
     */
    public function forUserWithIds(int $userId): SavedSearchTermsWithIds
    {
        $searches = $this->savedSearches->findForUser($userId);

        return new SavedSearchTermsWithIds(
            array_map(self::of(...), $searches),
            array_map(static fn (SavedSearch $search): int => (int) $search->getId(), $searches),
        );
    }
}

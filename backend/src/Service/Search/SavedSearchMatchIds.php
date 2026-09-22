<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchEntryRepository;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * The unread matching entry ids behind each saved search's sidebar badge. The
 * client counts them, and drops one the moment the user reads it, so the badge
 * falls without another scan. Read from the membership table (#1116), so the
 * set is exactly what opening the search lists — every search in one query.
 */
final readonly class SavedSearchMatchIds
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, list<int>> saved-search id => unread member entry ids
     */
    #[WithSpan]
    public function forAll(array $savedSearches, int $userId): array
    {
        return $this->entries->unreadMemberIdsBySavedSearch(
            $userId,
            array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $savedSearches),
        );
    }

    /**
     * A batch of one answers one list under the search's id; this unwraps it.
     *
     * @return list<int>
     */
    public function forOne(SavedSearch $savedSearch, int $userId): array
    {
        return array_merge(...$this->forAll([$savedSearch], $userId));
    }
}

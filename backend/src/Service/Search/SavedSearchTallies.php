<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\Model\SavedSearchTallyModel;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * What each saved search's badge and heading count: the unread member ids,
 * which the client drops one by one as they are read, and the member total.
 * Read from the membership table, every search in one query each.
 */
final readonly class SavedSearchTallies
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, SavedSearchTallyModel> saved-search id => its tally
     */
    #[WithSpan]
    public function forAll(array $savedSearches, int $userId): array
    {
        $ids = array_map(static fn (SavedSearch $savedSearch): int => $savedSearch->requireId(), $savedSearches);
        $unreadIds = $this->entries->unreadMemberIdsBySavedSearch($userId, $ids);
        $memberCounts = $this->entries->memberCountsBySavedSearch($userId, $ids);

        return array_combine($ids, array_map(
            static fn (int $id): SavedSearchTallyModel
                => new SavedSearchTallyModel($unreadIds[$id], $memberCounts[$id]),
            $ids,
        ));
    }

    public function forOne(SavedSearch $savedSearch, int $userId): SavedSearchTallyModel
    {
        return $this->forAll([$savedSearch], $userId)[$savedSearch->requireId()];
    }
}

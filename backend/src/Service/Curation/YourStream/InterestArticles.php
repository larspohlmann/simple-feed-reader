<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\SavedSearch;
use App\Enum\InclusionSurface;
use App\Repository\ReadingHistoryRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Curation\YourStream\Model\InterestInputsModel;

/** The articles a reader marked as interesting in their Your stream feeds, viewed ones aside, and their saved searches. */
final readonly class InterestArticles
{
    public const int SECTION_CAP = 200;

    public function __construct(private ReadingHistoryRepository $history, private SavedSearchRepository $savedSearches)
    {
    }

    public function collectInputs(int $userId): InterestInputsModel
    {
        $surface = InclusionSurface::YourStream;
        $articleIds = array_unique([
            ...$this->history->findFavoriteIds($userId, self::SECTION_CAP, $surface),
            ...$this->history->findKeptIds($userId, self::SECTION_CAP, $surface),
            ...$this->history->findLikedIds($userId, self::SECTION_CAP, $surface),
            ...$this->history->findSharedIds($userId, self::SECTION_CAP, $surface),
        ]);

        return new InterestInputsModel($userId, array_values($articleIds), $this->collectSearchTerms($userId));
    }

    /** @return list<string> in position order */
    private function collectSearchTerms(int $userId): array
    {
        $searches = $this->savedSearches->findForUser($userId);
        usort(
            $searches,
            static fn (SavedSearch $left, SavedSearch $right): int => [$left->getPosition(), $left->getId()]
                <=> [$right->getPosition(), $right->getId()],
        );

        return array_map(static fn (SavedSearch $search): string => $search->getTerm(), $searches);
    }
}

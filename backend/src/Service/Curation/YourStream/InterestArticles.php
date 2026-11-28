<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\SavedSearch;
use App\Enum\InclusionSurface;
use App\Repository\ReadingHistoryRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\TitledEntry;
use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;

/** The articles a reader marked as interesting in their Your stream feeds, viewed ones aside, and their saved searches. */
final readonly class InterestArticles
{
    public const int SECTION_CAP = 200;

    public function __construct(private ReadingHistoryRepository $history, private SavedSearchRepository $savedSearches)
    {
    }

    public function collectInputs(int $userId): InterestInputsModel
    {
        $history = $this->history->withSurface(InclusionSurface::YourStream);
        $sections = [
            $history->favorites($userId, self::SECTION_CAP),
            $history->kept($userId, self::SECTION_CAP),
            $history->liked($userId, self::SECTION_CAP),
            $history->shared($userId, self::SECTION_CAP),
        ];
        $articles = array_map(
            static fn (TitledEntry $titled): StreamEntryModel => StreamEntryModel::fromEntry($titled->entry),
            array_merge(...$sections),
        );

        return new InterestInputsModel($userId, $articles, $this->collectSearchTerms($userId));
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

<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport;

use App\Pagination\EntryCursor;
use App\Repository\EntryLabelRepository;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Service\Library\SavedListExport\Model\SavedListEntryModel;
use App\Service\Library\SavedListExport\Model\SavedListOriginModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedListWalk
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryLabelRepository $entryLabels,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return \Generator<int, list<SavedListEntryModel>>
     */
    public function batches(int $userId, SavedListScopeModel $scope): \Generator
    {
        $cursor = null;
        do {
            $query = $this->buildQuery($userId, $scope, $cursor);
            $rows = $this->entryList->listForUser($query);
            if ($rows === []) {
                return;
            }
            $cursor = $this->buildCursorAfter($rows[array_key_last($rows)]);
            yield $this->mapRows($userId, $rows);
            $this->entityManager->clear();
        } while (\count($rows) >= $query->limit);
    }

    private function buildQuery(int $userId, SavedListScopeModel $scope, ?EntryCursor $cursor): EntryQuery
    {
        $query = new EntryQuery(
            userId: $userId,
            view: $scope->view,
            cursor: $cursor,
            limit: EntryQuery::MAX_LIMIT,
            order: $scope->order,
        );

        return $scope->labelScope === null ? $query : $query->withLabelScope($scope->labelScope);
    }

    private function buildCursorAfter(EntryListRow $row): EntryCursor
    {
        return new EntryCursor(EntryListSort::PublishedDate->requireInstant($row), $row->entry->requireId());
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<SavedListEntryModel>
     */
    private function mapRows(int $userId, array $rows): array
    {
        $entryIds = array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
        $labelsByEntryId = $this->entryLabels->findLabelsByEntryIds($userId, $entryIds);

        return array_map(
            static fn (EntryListRow $row): SavedListEntryModel => self::mapRow(
                $row,
                array_column($labelsByEntryId[$row->entry->requireId()] ?? [], 'name'),
            ),
            $rows,
        );
    }

    /**
     * @param list<string> $labelNames
     */
    private static function mapRow(EntryListRow $row, array $labelNames): SavedListEntryModel
    {
        $entry = $row->entry;
        $feed = $entry->getFeed();
        $text = $entry->getText();

        return new SavedListEntryModel(
            $entry->getGuid(),
            $entry->getUrl(),
            $entry->getTitle(),
            $entry->getAuthor(),
            $text->getContentHtml() ?? $text->getSummary() ?? '',
            $entry->getEffectiveDate(),
            new SavedListOriginModel($row->subscription->title, $feed->getUrl(), $feed->getSiteUrl()),
            $labelNames,
        );
    }
}

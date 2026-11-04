<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Repository\EntryListRow;

/** One Trending card: the lead article, newest with a picture, whose duplicates are the other outlets (#2017). */
final readonly class TrendingStoryCardModel
{
    public function __construct(
        public EntryListRow $lead,
        public RankedStoryModel $story,
        public bool $viewed,
    ) {
    }

    /** @param non-empty-list<EntryListRow> $rows the story's article rows the reader can see */
    public static function fromRows(array $rows, RankedStoryModel $story, bool $viewed): self
    {
        $onePerFeed = self::newestPerFeed($rows);
        $lead = self::leadOf($onePerFeed);

        return new self(
            $lead->withDuplicates(self::otherFeedsThan($lead, $onePerFeed)),
            $story,
            $viewed,
        );
    }

    /**
     * The lead's own copies elsewhere join its outlets, one per feed, the lead's feed never; a feed the story did not
     * count yet is one more source.
     *
     * @param list<EntryListRow> $copies
     */
    public function withCopies(array $copies): self
    {
        $countedFeedIds = self::feedIdsOf([$this->lead, ...$this->lead->duplicates]);
        $outlets = self::otherFeedsThan(
            $this->lead,
            self::newestPerFeed([...$this->lead->duplicates, ...$copies]),
        );

        return new self(
            $this->lead->withDuplicates($outlets),
            $this->story->withMoreOutlets(\count(array_diff(self::feedIdsOf($outlets), $countedFeedIds))),
            $this->viewed,
        );
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function feedIdsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->getFeed()->requireId(), $rows);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return ($rows is non-empty-list ? non-empty-list<EntryListRow> : list<EntryListRow>) newest first
     */
    private static function newestPerFeed(array $rows): array
    {
        usort($rows, static fn (EntryListRow $left, EntryListRow $right): int => [
            $right->entry->getEffectiveDate(),
            $right->entry->requireId(),
        ] <=> [$left->entry->getEffectiveDate(), $left->entry->requireId()]);

        $byFeed = [];
        foreach ($rows as $row) {
            $byFeed[$row->entry->getFeed()->requireId()] ??= $row;
        }

        return array_values($byFeed);
    }

    /**
     * @param list<EntryListRow> $onePerFeed
     *
     * @return list<EntryListRow>
     */
    private static function otherFeedsThan(EntryListRow $lead, array $onePerFeed): array
    {
        $leadFeedId = $lead->entry->getFeed()->requireId();

        return array_values(array_filter(
            $onePerFeed,
            static fn (EntryListRow $row): bool => $row->entry->getFeed()->requireId() !== $leadFeedId,
        ));
    }

    /** @param non-empty-list<EntryListRow> $rows */
    private static function leadOf(array $rows): EntryListRow
    {
        foreach ($rows as $row) {
            if ($row->entry->getImageUrl() !== null) {
                return $row;
            }
        }

        return $rows[0];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Repository\EntryListRow;

/** One Trending card: an article per feed, led by the newest with a picture (#2017). */
final readonly class TrendingStoryCardModel
{
    /** @param list<EntryListRow> $others newest first */
    public function __construct(
        public EntryListRow $lead,
        public array $others,
        public int $outletCount,
        public bool $viewed,
    ) {
    }

    /** @param non-empty-list<EntryListRow> $newestFirst the story's article rows the reader can see */
    public static function fromRows(array $newestFirst, int $outletCount, bool $viewed): self
    {
        $onePerFeed = self::newestPerFeed($newestFirst);
        $lead = self::leadOf($onePerFeed);
        $others = array_values(array_filter($onePerFeed, static fn (EntryListRow $row): bool => $row !== $lead));

        return new self($lead, $others, $outletCount, $viewed);
    }

    /**
     * @param non-empty-list<EntryListRow> $newestFirst
     *
     * @return non-empty-list<EntryListRow>
     */
    private static function newestPerFeed(array $newestFirst): array
    {
        $byFeed = [];
        foreach ($newestFirst as $row) {
            $byFeed[$row->entry->getFeed()->requireId()] ??= $row;
        }

        return array_values($byFeed);
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

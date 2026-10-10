<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingPostEnrichment;
use App\Service\LinkCard\Support\LinkPost;
use App\Service\LinkCard\Support\SharedLinks;
use Psr\Clock\ClockInterface;

/**
 * Flags the rows still being completed: a Bluesky post waiting for the AppView, or a young post sharing a link whose
 * cards were never tried. Runs after LinkCardLoader, whose cards it reads; one query and one cache read per page.
 */
final readonly class EntryPendingLoader
{
    private const string WALL_CLOCK = 'Y-m-d H:i:s';

    public function __construct(
        private PendingPostEnrichmentRepository $pendingPosts,
        private TriedPostRepository $triedPosts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows): array
    {
        $pendingIds = [
            ...$this->pendingPosts->queuedEntryIdsAmong(EntryListRow::entryIdsOf($rows)),
            ...$this->untriedLinkPostIds($rows),
        ];

        return array_map(static fn (EntryListRow $row): EntryListRow => self::flag($row, $pendingIds), $rows);
    }

    /** @param list<int> $pendingIds */
    private static function flag(EntryListRow $row, array $pendingIds): EntryListRow
    {
        return $row
            ->withDuplicates(array_map(
                static fn (EntryListRow $duplicate): EntryListRow => self::flag($duplicate, $pendingIds),
                $row->duplicates,
            ))
            ->withPending(\in_array($row->entry->getId(), $pendingIds, true));
    }

    /**
     * A post older than a queued Bluesky post may wait is left to its first open, so neither a post from before link
     * cards existed nor one whose tried marker expired shows as pending.
     *
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private function untriedLinkPostIds(array $rows): array
    {
        $bodiesById = $this->cardlessYoungPostBodies($rows);
        $untried = array_diff_key($bodiesById, array_flip($this->triedPosts->triedAmong(array_keys($bodiesById))));

        return array_keys(array_filter($untried, static fn (string $body): bool => SharedLinks::in($body) !== []));
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, string> the bodies of the card-less link posts created since the cutoff, by entry id
     */
    private function cardlessYoungPostBodies(array $rows): array
    {
        $cutoff = $this->youngCutoff();
        $bodies = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                if (self::isCardlessPostSince($member, $cutoff)) {
                    $bodies[$member->entry->requireId()] = (string) $member->entry->getContentHtml();
                }
            }
        }

        return $bodies;
    }

    private static function isCardlessPostSince(EntryListRow $row, string $cutoff): bool
    {
        return $row->linkCards === []
            && LinkPost::matches($row->entry)
            && $row->entry->getCreatedAt()->format(self::WALL_CLOCK) >= $cutoff;
    }

    /** createdAt holds a naive UTC wall clock, so the cutoff is compared as one, whatever the PHP time zone. */
    private function youngCutoff(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->sub(new \DateInterval(PendingPostEnrichment::GIVE_UP_AFTER))
            ->format(self::WALL_CLOCK);
    }
}

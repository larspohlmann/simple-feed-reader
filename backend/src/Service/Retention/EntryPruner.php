<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Repository\RetentionRepository;
use App\Service\Search\EntryIndexer;
use Symfony\Component\Clock\ClockInterface;

/**
 * Four passes: entries fetched before their retention window (a social network's feeds have their own), a feed's
 * entries beyond its cap, a saved feed's entries nobody flags any more, then completed runs left empty.
 * Age counts from the fetch (`createdAt`), never `effectiveDate`: a backfilled article was re-added forever (#384).
 */
final readonly class EntryPruner
{
    private const int DELETE_CHUNK_SIZE = 500;
    private const int DEFAULT_MAX_ENTRIES_PER_FEED = 2000;

    /** A floor, not a skip: both passes keep a feed's newest 20, however old. */
    private const int MIN_ENTRIES_PER_FEED = 20;

    public function __construct(
        private RetentionRepository $retention,
        private RetentionSettings $settings,
        private ClockInterface $clock,
        private EntryIndexer $indexer,
        private int $maxEntriesPerFeed = self::DEFAULT_MAX_ENTRIES_PER_FEED,
    ) {
    }

    /**
     * Counts deleted entries only: the empty-run pass deletes runs, not entries.
     *
     * @throws \DateMalformedStringException
     */
    public function prune(): int
    {
        $deletedEntries = $this->pruneByAge() + $this->pruneByFeedCap() + $this->pruneUnsaved();
        $this->retention->deleteEmptyCompletedRuns();

        return $deletedEntries;
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function pruneByAge(): int
    {
        $windows = $this->settings->current();
        $socialCutoff = $this->daysAgo($windows->socialDays);
        $cutoff = $this->daysAgo($windows->entryDays);

        return $this->pruneFetchedBefore($this->retention->socialFeedIdsFetchedBefore($socialCutoff), $socialCutoff)
            + $this->pruneFetchedBefore($this->retention->nonSocialFeedIdsFetchedBefore($cutoff), $cutoff);
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function daysAgo(int $days): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d days', $days));
    }

    /**
     * @param list<int> $feedIds
     */
    private function pruneFetchedBefore(array $feedIds, \DateTimeImmutable $cutoff): int
    {
        $deleted = 0;
        foreach ($feedIds as $feedId) {
            $deleted += $this->deleteByIds(
                $this->retention->staleIdsPastBoundary((int) $feedId, self::MIN_ENTRIES_PER_FEED, $cutoff),
            );
        }

        return $deleted;
    }

    private function pruneByFeedCap(): int
    {
        $cap = $this->clampedMaxEntriesPerFeed();

        $deleted = 0;
        foreach ($this->retention->feedIdsOverCap($cap) as $feedId) {
            $deleted += $this->deleteByIds($this->retention->idsPastBoundary((int) $feedId, $cap));
        }

        return $deleted;
    }

    private function pruneUnsaved(): int
    {
        $deleted = 0;
        foreach ($this->retention->savedFeedIds() as $feedId) {
            $deleted += $this->deleteByIds($this->retention->unsavedIdsInSavedFeed((int) $feedId));
        }

        return $deleted;
    }

    /** An override below the floor would defeat it, and zero would make the boundary's offset negative. */
    private function clampedMaxEntriesPerFeed(): int
    {
        return max(self::MIN_ENTRIES_PER_FEED, $this->maxEntriesPerFeed);
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        // A bulk DELETE fires no lifecycle event, so the index is told here, one chunk at a time.
        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->retention->deleteEntries($chunk);
            $this->indexer->forget($chunk);
        }

        return \count($ids);
    }
}

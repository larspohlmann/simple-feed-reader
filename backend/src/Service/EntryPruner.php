<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retention runs three independent passes:
 *
 *  1. By age — deletes entries **fetched** more than 90 days ago, sparing any
 *     a user marked favorite or kept, and never a feed's newest 20 regardless
 *     of age. Age is measured from the fetch (`createdAt`), not from
 *     `effectiveDate`: `effectiveDate` is the list-sort instant and, for a
 *     backfilled or republished article, can sit far in the past on the very
 *     fetch that stored it — measuring retention from it deleted such an
 *     entry immediately, and the next refresh re-added it, forever (#384).
 *  2. By per-feed count — deletes a feed's oldest entries beyond a cap, same
 *     sparing rule.
 *  3. Empty completed recommendation runs — a run whose entries were all
 *     pruned by (1) or (2) left an empty husk behind (its items die via the
 *     DB FK cascade when their entry goes); this pass removes the husk.
 *
 * The count cap bounds a single feed's footprint regardless of age: a feed
 * whose article URLs change every fetch (cache-buster query params on a
 * scraped page being the easy case) mints new GUIDs on every refresh, so the
 * age pass alone lets it accumulate ~100k rows inside the 90-day window. The
 * cap keeps that finite. Ids are selected first, then deleted in chunks —
 * portable across SQLite and MySQL. Read-state rows die with their entry via
 * the DB FK cascade.
 */
final class EntryPruner
{
    private const int RETENTION_DAYS = 90;
    private const int DELETE_CHUNK_SIZE = 500;

    /**
     * Comfortably above any normal feed's 90-day volume, so the cap only ever
     * bites pathological/abusive feeds; overridable via the service definition
     * for operators who want it tighter.
     */
    private const int DEFAULT_MAX_ENTRIES_PER_FEED = 2000;

    /**
     * A feed's floor. Retention now measures from the fetch, so a low-volume
     * feed whose articles are all older than the window would otherwise
     * empty itself completely. A floor, not a skip: "spare feeds with 20 or
     * fewer" would still let a feed of 25 old entries drop to zero.
     */
    private const int MIN_ENTRIES_PER_FEED = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly int $maxEntriesPerFeed = self::DEFAULT_MAX_ENTRIES_PER_FEED,
    ) {
    }

    /**
     * The empty-run pass is bookkeeping, not something the user's refresh
     * summary should count: it deletes recommendation runs, not entries, so
     * folding it into the total would report "3 entries pruned" for a
     * refresh that removed zero entries and three empty runs.
     *
     * @throws \DateMalformedStringException
     */
    public function prune(): int
    {
        $deletedEntries = $this->pruneByAge() + $this->pruneByFeedCap();
        $this->pruneEmptyRuns();

        return $deletedEntries;
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function pruneByAge(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        $deleted = 0;
        foreach ($this->feedIdsFetchedBefore($cutoff) as $feedId) {
            $deleted += $this->deleteByIds($this->staleEntryIdsBeyondFloor((int) $feedId, $cutoff));
        }

        return $deleted;
    }

    /**
     * @return list<int> — only feeds with at least one stale entry are worth scanning.
     */
    private function feedIdsFetchedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT DISTINCT IDENTITY(e.feed) FROM %s e WHERE e.createdAt < :cutoff',
            Entry::class,
        ))
            ->setParameter('cutoff', $cutoff)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /**
     * A feed's entries beyond its newest-twenty floor, narrowed to the ones
     * fetched before the retention cutoff. The floor and the age cutoff are
     * two independent conditions on the same candidate set, not one query, so
     * a feed just over the floor with entries of mixed age is not all-or-
     * nothing: only the stale ones among the excess are deleted.
     *
     * @return list<int>
     */
    private function staleEntryIdsBeyondFloor(int $feedId, \DateTimeImmutable $cutoff): array
    {
        $beyondFloor = $this->entryIdsBeyond($feedId, self::MIN_ENTRIES_PER_FEED);
        if ($beyondFloor === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e WHERE e.id IN (:ids) AND e.createdAt < :cutoff',
            Entry::class,
        ))
            ->setParameter('ids', $beyondFloor)
            ->setParameter('cutoff', $cutoff)
            ->getSingleColumnResult();

        return $ids;
    }

    private function pruneByFeedCap(): int
    {
        $deleted = 0;
        foreach ($this->feedIdsOverCap() as $feedId) {
            $deleted += $this->deleteByIds($this->entryIdsBeyond((int) $feedId, $this->maxEntriesPerFeed));
        }

        return $deleted;
    }

    /**
     * @return list<int> — only feeds over the cap are worth scanning.
     */
    private function feedIdsOverCap(): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT IDENTITY(e.feed) FROM %s e GROUP BY e.feed HAVING COUNT(e.id) > :cap',
            Entry::class,
        ))
            ->setParameter('cap', $this->maxEntriesPerFeed)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /**
     * A feed's non-protected entry ids beyond its newest `$keep`, in fetch
     * order (later fetch = newer; id breaks a tie inside one run). Used by
     * both passes: the floor that spares a small feed, and the cap that
     * bounds a huge one.
     *
     * The newest-`$keep` boundary spans every entry in the feed, favorites
     * and kept included, so a handful of protected articles cannot push it
     * around; a protected entry beyond that boundary still survives, since
     * only the non-protected ones among the excess come back.
     *
     * @return list<int>
     */
    private function entryIdsBeyond(int $feedId, int $keep): array
    {
        /** @var list<int> $orderedIds */
        $orderedIds = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e WHERE e.feed = :feed ORDER BY e.createdAt DESC, e.id DESC',
            Entry::class,
        ))
            ->setParameter('feed', $feedId)
            ->getSingleColumnResult();

        $excessIds = \array_slice($orderedIds, $keep);
        if ($excessIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e WHERE e.id IN (:ids) AND %s',
            Entry::class,
            $this->notProtectedDql(),
        ))
            ->setParameter('ids', $excessIds)
            ->setParameter('true', true, Types::BOOLEAN)
            ->getSingleColumnResult();

        return $ids;
    }

    /**
     * A completed run left with no items (every one of them pruned along with
     * its entry) is dead weight — never touches pending/running/failed runs,
     * since a fresh snapshot legitimately has no items yet.
     */
    private function pruneEmptyRuns(): int
    {
        $affected = $this->em->createQuery(sprintf(
            'DELETE FROM %s r WHERE r.status = :completed AND NOT EXISTS (SELECT i.id FROM %s i WHERE i.run = r)',
            RecommendationRun::class,
            RecommendationItem::class,
        ))
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
            ->execute();

        // A DQL DELETE returns its affected-row count, but Doctrine types
        // execute() as mixed; narrow it rather than blind-casting.
        return \is_int($affected) ? $affected : 0;
    }

    /** Shared guard: an entry is protected iff any user favorited or kept it. */
    private function notProtectedDql(): string
    {
        return sprintf(
            'NOT EXISTS (
                SELECT IDENTITY(s.user) FROM %s s
                WHERE s.entry = e AND (s.isFavorite = :true OR s.isKept = :true)
            )',
            EntryState::class,
        );
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
                ->setParameter('ids', $chunk)
                ->execute();
        }

        return \count($ids);
    }
}

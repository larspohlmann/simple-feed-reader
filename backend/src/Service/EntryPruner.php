<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Service\Search\EntryIndexer;
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
        private readonly EntryIndexer $indexer,
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
            $deleted += $this->deleteByIds(
                $this->deletableIdsPastBoundary((int) $feedId, self::MIN_ENTRIES_PER_FEED, $cutoff),
            );
        }

        return $deleted;
    }

    /**
     * @return list<int> — only feeds with a stale entry are worth ranking at all.
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

    private function pruneByFeedCap(): int
    {
        $cap = $this->clampedMaxEntriesPerFeed();

        $deleted = 0;
        foreach ($this->feedIdsOverCap($cap) as $feedId) {
            $deleted += $this->deleteByIds(
                $this->deletableIdsPastBoundary((int) $feedId, $cap, null),
            );
        }

        return $deleted;
    }

    /**
     * `maxEntriesPerFeed` is a constructor argument an operator can override
     * via the service definition; clamping it here, rather than trusting the
     * configured value, keeps the floor structural instead of
     * configuration-dependent — a value below it would silently defeat
     * `MIN_ENTRIES_PER_FEED`, and zero would turn `rankBoundaryBeyond()`'s
     * `setFirstResult($keep - 1)` negative.
     */
    private function clampedMaxEntriesPerFeed(): int
    {
        return max(self::MIN_ENTRIES_PER_FEED, $this->maxEntriesPerFeed);
    }

    /**
     * @return list<int> — only feeds over the cap are worth ranking at all.
     */
    private function feedIdsOverCap(int $cap): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT IDENTITY(e.feed) FROM %s e GROUP BY e.feed HAVING COUNT(e.id) > :cap',
            Entry::class,
        ))
            ->setParameter('cap', $cap)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /**
     * A feed's deletable entries past its `keep`-th newest — the shape both
     * passes need, differing only in where they put the boundary and whether
     * they also demand staleness.
     *
     * The cap pass passes no cutoff: everything past the boundary goes. The
     * age pass passes one, and the two conditions stay independent rather than
     * collapsing into a single query, so a feed just over the floor holding
     * entries of mixed age is not all-or-nothing — only the stale ones among
     * the excess are deleted.
     *
     * @return list<int>
     */
    private function deletableIdsPastBoundary(int $feedId, int $keep, ?\DateTimeImmutable $cutoff): array
    {
        $boundary = $this->rankBoundaryBeyond($feedId, $keep);
        if ($boundary === null) {
            return [];
        }

        $query = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e
             WHERE e.feed = :feed
             AND %s
             %s
             AND %s',
            Entry::class,
            $this->pastBoundaryDql(),
            $cutoff === null ? '' : 'AND e.createdAt < :cutoff',
            $this->notProtectedDql(),
        ))
            ->setParameter('feed', $feedId)
            ->setParameter('boundaryCreatedAt', $boundary->createdAt)
            ->setParameter('boundaryId', $boundary->id)
            ->setParameter('true', true, Types::BOOLEAN);

        if ($cutoff !== null) {
            $query->setParameter('cutoff', $cutoff);
        }

        /** @var list<int> $ids */
        $ids = $query->getSingleColumnResult();

        return $ids;
    }

    /**
     * The fetch-order position of a feed's `keep`-th newest entry (later
     * fetch = newer; id breaks a tie inside one run), or null when the feed
     * doesn't have that many entries — the floor and the cap share this one
     * ordering, so the two can never disagree about which entries are old.
     *
     * `setFirstResult($keep - 1)` + `setMaxResults(1)` walks exactly `keep`
     * rows of `idx_entry_feed_created (feed_id, created_at, id)` and stops:
     * cost is O(keep), not O(feed size). A correlated `COUNT` here looked
     * equivalent on paper but re-scanned the whole feed once per row —
     * quadratic in feed size and, since the feed over the cap is by
     * definition the database's largest, dominant regardless of how many
     * other feeds exist to guard against (#384 round 3).
     *
     * Ranks every entry in the feed, favorites and kept included, so a
     * handful of protected articles cannot shift the boundary; a protected
     * entry beyond it still survives via `notProtectedDql()` in the caller.
     */
    private function rankBoundaryBeyond(int $feedId, int $keep): ?EntryRankBoundary
    {
        /** @var list<array{createdAt: \DateTimeImmutable, id: int}> $rows */
        $rows = $this->em->createQuery(sprintf(
            'SELECT e.createdAt AS createdAt, e.id AS id FROM %s e
             WHERE e.feed = :feed
             ORDER BY e.createdAt DESC, e.id DESC',
            Entry::class,
        ))
            ->setParameter('feed', $feedId)
            ->setFirstResult($keep - 1)
            ->setMaxResults(1)
            ->getResult();

        if ($rows === []) {
            return null;
        }

        return new EntryRankBoundary($rows[0]['createdAt'], $rows[0]['id']);
    }

    /**
     * True when `e` ranks strictly older than `:boundaryCreatedAt`/
     * `:boundaryId` — an index-servable keyset range on
     * `idx_entry_feed_created`, the same two-column comparison
     * EntryRepository::applyCursor() builds for the entry list's own cursor.
     */
    private function pastBoundaryDql(): string
    {
        return '(e.createdAt < :boundaryCreatedAt
                 OR (e.createdAt = :boundaryCreatedAt AND e.id < :boundaryId))';
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

        // This DQL DELETE bypasses the ORM's lifecycle events entirely, which
        // is exactly why the index is told explicitly rather than through a
        // listener that a bulk delete would never fire. Each chunk's ids are
        // captured here, before its DELETE runs — there is no entity left
        // afterwards to read an id from.
        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
                ->setParameter('ids', $chunk)
                ->execute();
            $this->indexer->forget($chunk);
        }

        return \count($ids);
    }
}

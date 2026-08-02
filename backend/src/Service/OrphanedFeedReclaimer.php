<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A feed nobody subscribes to is nobody's content: it costs storage, and the
 * refresh run would keep fetching it forever. This is the only place such a
 * feed is deleted, so the immediate path (the last unsubscribe, a user
 * deletion) and the sweep cannot drift apart.
 *
 * The no-subscriber condition is re-checked INSIDE the DELETE rather than
 * trusted from the preceding SELECT. Between selecting a candidate and
 * deleting it, another user can subscribe; without the guard that subscription
 * row would be silently taken by `subscription.feed_id`'s ON DELETE CASCADE —
 * a lost subscription, which is far worse than a feed that survives one sweep.
 * Correlating against `subscription` (a different table from the DELETE
 * target) is legal on both MySQL and SQLite.
 *
 * The feed's entries and their read state follow through the FK cascade on
 * `entry.feed_id` and `entry_state.entry_id`.
 *
 * Bulk DQL bypasses the unit of work, so a Feed the caller still holds is
 * stale once this returns. Callers pass an id and must not touch that entity
 * afterwards.
 */
final readonly class OrphanedFeedReclaimer
{
    /** Same chunking as EntryPruner: keeps the IN() list off the parameter limit. */
    private const int DELETE_CHUNK_SIZE = 500;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** True when the feed had no subscriber left and was deleted. */
    public function reclaim(int $feedId): bool
    {
        return $this->deleteOrphans([$feedId]) > 0;
    }

    /** The safety net: every orphan currently in the database. */
    public function reclaimAll(): int
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->entityManager->createQuery(sprintf(
            'SELECT f.id FROM %s f WHERE %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))->getSingleColumnResult();

        return $this->deleteOrphans($feedIds);
    }

    /**
     * @param list<int> $feedIds
     */
    private function deleteOrphans(array $feedIds): int
    {
        if ([] === $feedIds) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($feedIds, self::DELETE_CHUNK_SIZE) as $chunk) {
            $affected = $this->entityManager->createQuery(sprintf(
                'DELETE FROM %s f WHERE f.id IN (:feedIds) AND %s',
                Feed::class,
                $this->hasNoSubscriberDql(),
            ))
                ->setParameter('feedIds', $chunk)
                ->execute();

            // A DQL DELETE returns its affected-row count, but Doctrine types
            // execute() as mixed; narrow it rather than blind-casting.
            $deleted += \is_int($affected) ? $affected : 0;
        }

        return $deleted;
    }

    private function hasNoSubscriberDql(): string
    {
        return sprintf(
            'NOT EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)',
            Subscription::class,
        );
    }
}

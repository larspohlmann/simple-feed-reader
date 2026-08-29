<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Marks a set of entries read for one user, by entry state alone.
 *
 * The feed and tag lists mark read by advancing a subscription watermark
 * (`MarkReadService`); a list that is not scoped to a feed cannot. A search
 * spans every feed, and the for-you feed is a ranked selection across them, so
 * for both the only truthful record is a per-entry state row: flipped where the
 * reader had explicitly marked the entry unread, created where no row exists.
 *
 * Batched so a broad search term or a long run of picks cannot pull every id
 * into memory at once.
 */
final readonly class BulkEntryReadMarker
{
    private const int BATCH = 500;

    public function __construct(
        private EntryStateRepository $states,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<int> $entryIds */
    public function markRead(int $userId, array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        $now = $this->clock->now();
        foreach (array_chunk($entryIds, self::BATCH) as $chunk) {
            $this->flipExistingUnread($userId, $chunk, $now);
            $this->createMissing($userId, $chunk, $now);
            $this->em->flush();
            $this->em->clear();
        }
    }

    /** @param list<int> $entryIds */
    private function flipExistingUnread(int $userId, array $entryIds, \DateTimeImmutable $now): void
    {
        $this->em->createQuery(
            'UPDATE ' . EntryState::class . ' es
             SET es.isHidden = :true, es.hiddenAt = :now
             WHERE es.user = :user AND es.isHidden = :false AND IDENTITY(es.entry) IN (:ids)',
        )
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $userId)
            ->setParameter('ids', $entryIds)
            ->execute();
    }

    /** @param list<int> $entryIds */
    private function createMissing(int $userId, array $entryIds, \DateTimeImmutable $now): void
    {
        $withState = $this->states->entryIdsWithStateForUser($userId, $entryIds);
        $missing = array_values(array_diff($entryIds, $withState));
        if ($missing === []) {
            return;
        }
        $userRef = $this->em->getReference(User::class, $userId)
            ?? throw new \LogicException('The current user has no reference.');
        foreach ($missing as $entryId) {
            $entryRef = $this->em->getReference(Entry::class, $entryId)
                ?? throw new \LogicException('An entry just selected for marking has no reference.');
            $state = new EntryState($userRef, $entryRef);
            $state->hide($now);
            $this->em->persist($state);
        }
    }
}

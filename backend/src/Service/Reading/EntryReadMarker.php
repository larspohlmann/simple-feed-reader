<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryReadMarkRepository;
use App\Repository\EntryStateRepository;
use App\Repository\ReadMarking;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The mark-read services' one marker: by subscription watermark for a scope of subscriptions (all, feed, tag),
 * or by entry state for the lists no watermark can scope (search, saved searches, For You, a batch of ids).
 */
final readonly class EntryReadMarker
{
    private const int BATCH = 500;

    public function __construct(
        private EntryStateRepository $states,
        private EntryReadMarkRepository $readMarks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<Subscription> $subscriptions */
    public function markSubscriptionsReadUntil(int $userId, array $subscriptions, \DateTimeImmutable $until): void
    {
        if ($subscriptions === []) {
            return;
        }

        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[] = $subscription->getFeed()->requireId();
            $this->advanceWatermark($subscription, $until);
        }

        // Atomic: the read-flip joins the transaction, which flushes the watermark changes before it commits.
        $this->em->wrapInTransaction(function () use ($userId, $feedIds, $until): void {
            $this->readMarks->hideUnreadInFeedsUntil(new ReadMarking($userId, $this->clock->now()), $feedIds, $until);
        });
    }

    /**
     * Batched, so a broad list never loads every id at once. The ids must be distinct and exist: a missing state
     * row is persisted by reference, so a pruned or repeated id fails the insert.
     *
     * @param list<int> $entryIds
     */
    public function markEntriesRead(int $userId, array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        $marking = new ReadMarking($userId, $this->clock->now());
        foreach (array_chunk($entryIds, self::BATCH) as $chunk) {
            $this->readMarks->hideUnreadAmong($marking, $chunk);
            $this->createMissing($marking, $chunk);
            $this->em->flush();
            $this->em->clear();
        }
    }

    private function advanceWatermark(Subscription $subscription, \DateTimeImmutable $until): void
    {
        $current = $subscription->getMarkedReadUntil();
        if ($current === null || $current < $until) {
            $subscription->setMarkedReadUntil($until);
        }
    }

    /** @param list<int> $entryIds */
    private function createMissing(ReadMarking $marking, array $entryIds): void
    {
        $withState = $this->states->entryIdsWithStateForUser($marking->userId, $entryIds);
        $missing = array_values(array_diff($entryIds, $withState));
        if ($missing === []) {
            return;
        }
        $userRef = $this->em->getReference(User::class, $marking->userId)
            ?? throw new \LogicException('The current user has no reference.');
        foreach ($missing as $entryId) {
            $entryRef = $this->em->getReference(Entry::class, $entryId)
                ?? throw new \LogicException('An entry just selected for marking has no reference.');
            $state = new EntryState($userRef, $entryRef);
            $state->hide($marking->at);
            $this->em->persist($state);
        }
    }
}

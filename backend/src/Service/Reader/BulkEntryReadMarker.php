<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryReadMarkRepository;
use App\Repository\EntryStateRepository;
use App\Repository\ReadMarking;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Marks entries read by entry state alone, for lists no subscription watermark can scope (search, For You): flips
 * an explicit unread, creates a missing row. Batched so a broad search cannot pull every id into memory at once.
 */
final readonly class BulkEntryReadMarker
{
    private const int BATCH = 500;

    public function __construct(
        private EntryStateRepository $states,
        private EntryReadMarkRepository $readMarks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<int> $entryIds Distinct ids of existing entries: a missing state row
     *  is persisted by reference, so a pruned or repeated id fails the insert. */
    public function markRead(int $userId, array $entryIds): void
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

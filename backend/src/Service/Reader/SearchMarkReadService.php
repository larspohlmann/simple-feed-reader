<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Repository\EntryStateRepository;
use App\Service\Search\SearchTerms;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Marks read every unread entry matching a search term for one user. A search
 * spans every feed and is a content filter, so — unlike feed/tag mark-read —
 * there is no watermark to bump: each matching entry needs an EntryState row
 * with isRead=true (created when absent, flipped when an explicit unread row
 * exists). Batched to bound memory on broad terms.
 */
final readonly class SearchMarkReadService
{
    private const int BATCH = 500;

    public function __construct(
        private EntryListRepository $entries,
        private EntryStateRepository $states,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function mark(User $user, string $rawQuery, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();
        $ids = $this->entries->unreadMatchingEntryIdsForUser(
            new EntrySearchQuery($userId, SearchTerms::fromInput($rawQuery)),
            $until,
        );
        if ($ids === []) {
            return;
        }

        $now = $this->clock->now();
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
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
             SET es.isRead = :true, es.readAt = :now
             WHERE es.user = :user AND es.isRead = :false AND IDENTITY(es.entry) IN (:ids)',
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
                ?? throw new \LogicException('An entry this search just matched has no reference.');
            $state = new EntryState($userRef, $entryRef);
            $state->markRead($now);
            $this->em->persist($state);
        }
    }
}

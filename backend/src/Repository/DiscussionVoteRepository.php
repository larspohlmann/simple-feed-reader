<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\VoteScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscussionVote>
 */
final class DiscussionVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscussionVote::class);
    }

    public function findOneFor(User $user, Entry $entry): ?DiscussionVote
    {
        return $this->findOneBy(['user' => $user, 'entry' => $entry]);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, VoteScore> the user's votes, by entry id
     */
    public function scoresAmong(int $userId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{entryId: int, score: VoteScore}> $rows */
        $rows = $this->createQueryBuilder('dv')
            ->select('e.id AS entryId', 'dv.score AS score')
            ->join('dv.entry', 'e')
            ->andWhere('dv.user = :user')
            ->andWhere('e.id IN (:entryIds)')
            ->setParameter('user', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        $scores = [];
        foreach ($rows as $row) {
            $scores[$row['entryId']] = $row['score'];
        }

        return $scores;
    }
}

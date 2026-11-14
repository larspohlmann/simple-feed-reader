<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\VoteScore;
use Doctrine\DBAL\Types\Types;
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

    /** An upsert, because two first votes of one user on one post can race for the unique row. */
    public function store(DiscussionVote $vote): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = DatabasePlatform::isMySql($connection)
            ? 'ON DUPLICATE KEY UPDATE social_account_id = VALUES(social_account_id), score = VALUES(score),'
                . ' voted_at = VALUES(voted_at)'
            : 'ON CONFLICT (user_id, entry_id) DO UPDATE SET social_account_id = excluded.social_account_id,'
                . ' score = excluded.score, voted_at = excluded.voted_at';

        $connection->executeStatement(
            'INSERT INTO discussion_vote (user_id, entry_id, social_account_id, score, voted_at)'
                . ' VALUES (?, ?, ?, ?, ?) ' . $onConflict,
            [
                $vote->getUser()->requireId(),
                $vote->getEntry()->requireId(),
                $vote->getSocialAccount()->requireId(),
                $vote->getScore()->value,
                $vote->getVotedAt(),
            ],
            [Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::SMALLINT, Types::DATETIME_IMMUTABLE],
        );
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

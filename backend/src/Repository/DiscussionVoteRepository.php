<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\User;
use App\Enum\VoteScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    public function findOneFor(User $user, CanonicalItem $item): ?DiscussionVote
    {
        return $this->findOneBy(['user' => $user, 'canonicalItem' => $item]);
    }

    /** An upsert, because two first votes of one user on one post can race for the unique row. */
    public function store(DiscussionVote $vote): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = DatabasePlatform::isMySql($connection)
            ? 'ON DUPLICATE KEY UPDATE social_account_id = VALUES(social_account_id), score = VALUES(score),'
                . ' voted_at = VALUES(voted_at)'
            : 'ON CONFLICT (user_id, canonical_item_id) DO UPDATE SET social_account_id = excluded.social_account_id,'
                . ' score = excluded.score, voted_at = excluded.voted_at';

        $connection->executeStatement(
            'INSERT INTO discussion_vote (user_id, canonical_item_id, social_account_id, score, voted_at)'
                . ' VALUES (?, ?, ?, ?, ?) ' . $onConflict,
            [
                $vote->getUser()->requireId(),
                $vote->getCanonicalItem()->requireId(),
                $vote->getSocialAccount()->requireId(),
                $vote->getScore()->value,
                $vote->getVotedAt(),
            ],
            [Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::SMALLINT, Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, VoteScore> the user's votes, by canonical item id
     */
    public function findScores(int $userId, array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        /** @var list<array{itemId: int, score: VoteScore}> $rows */
        $rows = $this->createQueryBuilder('dv')
            ->select('item.id AS itemId', 'dv.score AS score')
            ->join('dv.canonicalItem', 'item')
            ->andWhere('dv.user = :user')
            ->andWhere('item.id IN (:itemIds)')
            ->setParameter('user', $userId)
            ->setParameter('itemIds', $itemIds)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'score', 'itemId');
    }
}

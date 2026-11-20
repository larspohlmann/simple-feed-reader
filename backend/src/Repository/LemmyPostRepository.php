<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LemmyPost> */
final class LemmyPostRepository extends ServiceEntityRepository
{
    private const int ROWS_PER_STATEMENT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LemmyPost::class);
    }

    /**
     * @param list<int> $canonicalItemIds
     *
     * @return array<int, LemmyPost> keyed by canonical item id
     */
    public function findByCanonicalItemIds(array $canonicalItemIds): array
    {
        if ($canonicalItemIds === []) {
            return [];
        }

        /** @var list<LemmyPost> $rows */
        $rows = $this->createQueryBuilder('post')
            ->where('IDENTITY(post.canonicalItem) IN (:canonicalItemIds)')
            ->setParameter('canonicalItemIds', $canonicalItemIds)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $byItemId = [];
        foreach ($rows as $row) {
            $byItemId[$row->getCanonicalItem()->requireId()] = $row;
        }

        return $byItemId;
    }

    /**
     * Upserts, because two copies of one new post can be stored at once; the later community wins.
     *
     * @param array<int, PostCommunity> $communitiesByItemId
     */
    public function storeCommunities(array $communitiesByItemId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = DatabasePlatform::buildUpsertClause(
            $connection,
            ['canonical_item_id'],
            ['community_identity', 'community_url'],
        );

        foreach (array_chunk($communitiesByItemId, self::ROWS_PER_STATEMENT, true) as $chunk) {
            $parameters = [];
            foreach ($chunk as $itemId => $community) {
                array_push($parameters, $itemId, $community->identity, $community->url);
            }

            $connection->executeStatement(
                'INSERT INTO lemmy_post (canonical_item_id, community_identity, community_url) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')) . ' ' . $onConflict,
                $parameters,
                array_merge(...array_fill(0, count($chunk), [Types::INTEGER, Types::STRING, Types::STRING])),
            );
        }
    }
}

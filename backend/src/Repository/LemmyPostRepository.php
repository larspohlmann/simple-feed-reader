<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LemmyPost> */
final class LemmyPostRepository extends ServiceEntityRepository
{
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
            ->getResult();

        $byItemId = [];
        foreach ($rows as $row) {
            $byItemId[$row->getCanonicalItem()->requireId()] = $row;
        }

        return $byItemId;
    }

    /** An upsert, because two copies of one new post can be stored at once; the later community wins. */
    public function storeCommunity(int $canonicalItemId, PostCommunity $community): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = DatabasePlatform::isMySql($connection)
            ? 'ON DUPLICATE KEY UPDATE community_identity = VALUES(community_identity),'
                . ' community_url = VALUES(community_url)'
            : 'ON CONFLICT (canonical_item_id) DO UPDATE SET community_identity = excluded.community_identity,'
                . ' community_url = excluded.community_url';

        $connection->executeStatement(
            'INSERT INTO lemmy_post (canonical_item_id, community_identity, community_url) VALUES (?, ?, ?) '
                . $onConflict,
            [$canonicalItemId, $community->identity, $community->url],
            [Types::INTEGER, Types::STRING, Types::STRING],
        );
    }
}

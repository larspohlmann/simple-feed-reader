<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\StoredList;
use App\Entity\ThreadPost;
use App\Service\LinkCard\Model\PlacedLinkCardModel;
use App\Service\Social\Model\ThreadPostModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ThreadPost> */
final class ThreadPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreadPost::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<ThreadPostModel>> by entry id, in position order
     */
    public function byEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        /**
         * @var list<array{
         *   id: int|string, entryId: int|string, url: ?string, publishedAt: ?\DateTimeImmutable,
         *   contentHtml: string, media: ?list<array<string, mixed>>
         * }> $rows
         */
        $rows = $this->createQueryBuilder('post')
            ->select(
                'post.id',
                'IDENTITY(post.entry) AS entryId',
                'post.url',
                'post.publishedAt',
                'post.contentHtml',
                'post.media',
            )
            ->andWhere('post.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('post.position', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $cards = $this->cardsByPostId(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['entryId']][] = new ThreadPostModel(
                $row['url'],
                $row['publishedAt'],
                $row['contentHtml'],
                StoredList::read($row['media'], EntryMedium::isComplete(...), EntryMedium::fromStored(...)),
                $cards[(int) $row['id']] ?? null,
            );
        }

        return $grouped;
    }

    /** @return list<string> the reply URLs of the entry's thread, in position order */
    public function urlsOf(Entry $entry): array
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->createQueryBuilder('post')
            ->select('post.url')
            ->andWhere('post.entry = :entry')
            ->andWhere('post.url IS NOT NULL')
            ->setParameter('entry', $entry)
            ->orderBy('post.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'url');
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, PlacedLinkCardModel> by thread post id
     */
    private function cardsByPostId(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        /**
         * @var list<array{
         *   postId: int|string, position: int|string, url: string, title: string, description: ?string,
         *   imageUrl: ?string, siteName: string
         * }> $rows
         */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select(
                'IDENTITY(card.threadPost) AS postId',
                'card.position',
                'card.url',
                'card.title',
                'card.description',
                'card.imageUrl',
                'card.siteName',
            )
            ->from(LinkCard::class, 'card')
            ->andWhere('card.threadPost IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->getQuery()
            ->getArrayResult();

        $placed = [];
        foreach ($rows as $row) {
            $placed[(int) $row['postId']] = new PlacedLinkCardModel(
                (int) $row['position'],
                new LinkCardContent(
                    $row['url'],
                    $row['title'],
                    $row['description'],
                    $row['imageUrl'],
                    $row['siteName'],
                ),
            );
        }

        return $placed;
    }
}

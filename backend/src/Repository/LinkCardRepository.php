<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkCard> */
final class LinkCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkCard::class);
    }

    /**
     * @param list<LinkCardContent> $stored the cards the entry already holds
     * @param list<LinkCardContent> $cards  in post order
     */
    public function append(Entry $entry, array $stored, array $cards): void
    {
        foreach ($cards as $index => $content) {
            $this->getEntityManager()->persist(new LinkCard($entry, \count($stored) + $index, $content));
        }
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<LinkCardContent>> in post order, by entry id
     */
    public function contentsByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        /**
         * @var list<array{
         *   entryId: int|string, url: string, title: string, description: ?string, imageUrl: ?string, siteName: string
         * }> $rows
         */
        $rows = $this->createQueryBuilder('card')
            ->select(
                'IDENTITY(card.entry) AS entryId',
                'card.url',
                'card.title',
                'card.description',
                'card.imageUrl',
                'card.siteName',
            )
            ->andWhere('card.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['entryId']][] = self::contentOf($row);
        }

        return $grouped;
    }

    public function contentAt(int $userId, int $entryId, int $position): LinkCardContent
    {
        /** @var array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string}|null $row */
        $row = $this->createQueryBuilder('card')
            ->select('card.url', 'card.title', 'card.description', 'card.imageUrl', 'card.siteName')
            ->join('card.entry', 'entry')
            ->join(
                Subscription::class,
                'subscription',
                'ON',
                'subscription.feed = entry.feed AND subscription.user = :user',
            )
            ->andWhere('entry.id = :entryId')
            ->andWhere('card.position = :position')
            ->setParameter('user', $userId)
            ->setParameter('entryId', $entryId)
            ->setParameter('position', $position)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null
            ? throw new RecordNotFoundException('No such entry.')
            : self::contentOf($row);
    }

    /** @param array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string} $row */
    private static function contentOf(array $row): LinkCardContent
    {
        return new LinkCardContent($row['url'], $row['title'], $row['description'], $row['imageUrl'], $row['siteName']);
    }
}

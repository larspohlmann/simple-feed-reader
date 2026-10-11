<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkCard> */
final class LinkCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkCard::class);
    }

    /** @param list<LinkCardContent> $cards the entry's first cards, in post order: it holds none yet */
    public function add(Entry $entry, array $cards): void
    {
        foreach ($cards as $position => $content) {
            $this->getEntityManager()->persist(new LinkCard($entry, $position, $content));
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
            $grouped[(int) $row['entryId']][] = new LinkCardContent(
                $row['url'],
                $row['title'],
                $row['description'],
                $row['imageUrl'],
                $row['siteName'],
            );
        }

        return $grouped;
    }
}

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

    /** @param list<LinkCardContent> $cards in post order, after the entry's $firstPosition cards */
    public function add(Entry $entry, int $firstPosition, array $cards): void
    {
        foreach ($cards as $index => $content) {
            $this->getEntityManager()->persist(new LinkCard($entry, $firstPosition + $index, $content));
        }
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<string>> the URLs their cards link, by entry id; an entry without cards is left out
     */
    public function urlsByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int|string, url: string}> $rows */
        $rows = $this->createQueryBuilder('card')
            ->select('IDENTITY(card.entry) AS entryId', 'card.url')
            ->andWhere('card.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['entryId']][] = $row['url'];
        }

        return $grouped;
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

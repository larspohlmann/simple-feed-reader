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

    /** @param list<LinkCardContent> $cards */
    public function replaceFor(Entry $entry, array $cards): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->createQuery(\sprintf('DELETE FROM %s card WHERE card.entry = :entry', LinkCard::class))
            ->setParameter('entry', $entry)
            ->execute();
        foreach ($cards as $position => $content) {
            $entityManager->persist(new LinkCard($entry, $position, $content));
        }
    }

    public function hasAnyFor(Entry $entry): bool
    {
        return $this->createQueryBuilder('card')
            ->select('card.id')
            ->andWhere('card.entry = :entry')
            ->setParameter('entry', $entry)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<LinkCard>>
     */
    public function findByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        /** @var list<LinkCard> $cards */
        $cards = $this->createQueryBuilder('card')
            ->addSelect('IDENTITY(card.entry) AS HIDDEN entryId')
            ->andWhere('IDENTITY(card.entry) IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('entryId', 'ASC')
            ->addOrderBy('card.position', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($cards as $card) {
            $grouped[$card->getEntryId()][] = $card;
        }

        return $grouped;
    }
}

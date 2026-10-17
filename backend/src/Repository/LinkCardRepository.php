<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Entity\ThreadPost;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\LinkCard\Model\PlacedLinkCardModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkCard> */
final class LinkCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkCard::class);
    }

    /** @param list<LinkCardContent> $cards in post order */
    public function append(Entry $entry, array $cards): void
    {
        $next = $this->nextPositionFor($entry);
        foreach ($cards as $index => $content) {
            $this->getEntityManager()->persist(new LinkCard($entry, $next + $index, $content));
        }
    }

    public function appendToThreadPost(ThreadPost $post, LinkCardContent $card): void
    {
        $entry = $post->getEntry();
        $this->getEntityManager()->persist(new LinkCard($entry, $this->nextPositionFor($entry), $card, $post));
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<LinkCardContent>> the root cards in post order, by entry id
     */
    public function contentsByEntryIds(array $entryIds): array
    {
        $grouped = [];
        foreach ($this->rootCardRows($entryIds) as $row) {
            $grouped[(int) $row['entryId']][] = self::contentOf($row);
        }

        return $grouped;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<PlacedLinkCardModel>> the root cards in post order, by entry id
     */
    public function placedByEntryIds(array $entryIds): array
    {
        $grouped = [];
        foreach ($this->rootCardRows($entryIds) as $row) {
            $grouped[(int) $row['entryId']][] = new PlacedLinkCardModel((int) $row['position'], self::contentOf($row));
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

    /** An entry's root and reply cards share one numbering, cards still waiting for the flush included. */
    private function nextPositionFor(Entry $entry): int
    {
        $highest = -1;
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof LinkCard && $scheduled->getEntry() === $entry) {
                $highest = max($highest, $scheduled->getPosition());
            }
        }
        if ($entry->getId() === null) {
            return $highest + 1;
        }
        $stored = $this->createQueryBuilder('card')
            ->select('MAX(card.position)')
            ->andWhere('card.entry = :entry')
            ->setParameter('entry', $entry)
            ->getQuery()
            ->getSingleScalarResult();

        return max($highest, $stored === null ? -1 : (int) $stored) + 1;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array{
     *   entryId: int|string, position: int|string, url: string, title: string, description: ?string,
     *   imageUrl: ?string, siteName: string
     * }>
     */
    private function rootCardRows(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /**
         * @var list<array{
         *   entryId: int|string, position: int|string, url: string, title: string, description: ?string,
         *   imageUrl: ?string, siteName: string
         * }> $rows
         */
        $rows = $this->createQueryBuilder('card')
            ->select(
                'IDENTITY(card.entry) AS entryId',
                'card.position',
                'card.url',
                'card.title',
                'card.description',
                'card.imageUrl',
                'card.siteName',
            )
            ->andWhere('card.entry IN (:entryIds)')
            ->andWhere('card.threadPost IS NULL')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /** @param array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string} $row */
    private static function contentOf(array $row): LinkCardContent
    {
        return new LinkCardContent($row['url'], $row['title'], $row['description'], $row['imageUrl'], $row['siteName']);
    }
}

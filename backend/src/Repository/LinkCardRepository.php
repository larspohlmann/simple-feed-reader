<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Entity\ThreadPost;
use App\Service\Content\LinkCard\Model\StoryLinkModel;
use App\Service\Content\LinkCard\Support\StoryLinkRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkCard> */
final class LinkCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkCard::class);
    }

    /** @param list<SharedLink> $links in post order */
    public function append(Entry $entry, array $links): void
    {
        $next = $this->computeNextPosition($entry);
        foreach ($links as $index => $link) {
            $this->getEntityManager()->persist(new LinkCard($entry, $next + $index, $link));
        }
    }

    public function appendToThreadPost(ThreadPost $post, SharedLink $link): void
    {
        $entry = $post->getEntry();
        $this->getEntityManager()->persist(new LinkCard($entry, $this->computeNextPosition($entry), $link, $post));
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<LinkCardContent>> the root cards in post order, by entry id
     */
    public function contentsByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /**
         * @var list<array{
         *   entryId: int|string, url: string, title: string, description: ?string,
         *   imageUrl: ?string, siteName: string
         * }> $rows
         */
        $rows = $this->createQueryBuilder('card')
            ->select(
                'IDENTITY(card.entry) AS entryId',
                'link.url',
                'link.title',
                'link.description',
                'link.imageUrl',
                'link.siteName',
            )
            ->join('card.link', 'link')
            ->andWhere('card.entry IN (:entryIds)')
            ->andWhere('card.threadPost IS NULL')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('card.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['entryId']][] = LinkCardContent::fromRow($row);
        }

        return $grouped;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, StoryLinkModel> each entry's first root card that names a story, by entry id
     */
    public function storyLinksByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /**
         * @var list<array{
         *   entry_id: int|string, url: string, url_hash: string, title: string,
         *   description: string|null, site_name: string
         * }> $rows
         */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT card.entry_id, ' . LinkLandingSql::URL . ' AS url, ' . LinkLandingSql::URL_HASH . ' AS url_hash,'
            . ' link.title, link.description, link.site_name'
            . ' FROM link_card card JOIN shared_link link ON link.id = card.shared_link_id'
            . LinkLandingSql::JOIN
            . ' WHERE card.entry_id IN (:entryIds) AND' . LinkLandingSql::FIRST_ROOT_CARD,
            ['entryIds' => $entryIds],
            ['entryIds' => ArrayParameterType::INTEGER],
        );

        $stories = [];
        foreach ($rows as $row) {
            if (StoryLinkRule::namesAStory($row['url'], $row['title'], $row['site_name'])) {
                $stories[(int) $row['entry_id']] = new StoryLinkModel(
                    $row['url_hash'],
                    $row['title'],
                    $row['description'],
                    $row['site_name'],
                );
            }
        }

        return $stories;
    }

    /** An entry's root and reply cards share one numbering, cards still waiting for the flush included. */
    private function computeNextPosition(Entry $entry): int
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
}

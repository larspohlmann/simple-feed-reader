<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\SharedLink;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharedLink> */
final class SharedLinkRepository extends ServiceEntityRepository
{
    private const int ROWS_PER_INSERT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedLink::class);
    }

    public function findOneByUrlHash(string $urlHash): ?SharedLink
    {
        return $this->findOneBy(['urlHash' => $urlHash]);
    }

    /**
     * @param list<string> $urlHashes
     *
     * @return array<string, SharedLink> by url hash
     */
    public function byUrlHashes(array $urlHashes): array
    {
        if ($urlHashes === []) {
            return [];
        }
        $found = [];
        foreach ($this->findBy(['urlHash' => array_values(array_unique($urlHashes))]) as $link) {
            $found[$link->getUrlHash()] = $link;
        }

        return $found;
    }

    /**
     * @param list<string> $urlHashes
     *
     * @return array<string, int> by url hash
     */
    public function idsByUrlHash(array $urlHashes): array
    {
        if ($urlHashes === []) {
            return [];
        }
        /** @var list<array{id: int|string, urlHash: string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('link.id', 'link.urlHash')
            ->andWhere('link.urlHash IN (:urlHashes)')
            ->setParameter('urlHashes', array_values(array_unique($urlHashes)))
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['urlHash']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Skips a hash another writer stored first, so a race never raises a unique violation that closes the manager.
     *
     * @param list<NewSharedLinkModel> $links
     */
    public function insertMissing(array $links): void
    {
        if ($links === []) {
            return;
        }
        $connection = $this->getEntityManager()->getConnection();
        BulkInsert::into(
            sprintf(
                'INSERT %s INTO shared_link (url, url_hash, title, description, image_url, site_name, language,'
                . ' language_source, created_at)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            self::ROWS_PER_INSERT,
        )->rows($connection, array_map(self::rowOf(...), $links));
    }

    /** A row no card and no quote references any more, and created before $before, the grace a writer's flush needs. */
    public function deleteUnreferencedCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_link WHERE created_at < ?'
            . ' AND NOT EXISTS (SELECT 1 FROM link_card card WHERE card.shared_link_id = shared_link.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM entry quoting WHERE quoting.quoted_shared_link_id = shared_link.id)',
            [$before->format('Y-m-d H:i:s')],
        );
    }

    /** @return array<int, string> by shared link id */
    public function urlsAfterId(int $afterId, int $limit): array
    {
        /** @var list<array{id: int|string, url: string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('link.id', 'link.url')
            ->andWhere('link.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('link.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $urls = [];
        foreach ($rows as $row) {
            $urls[(int) $row['id']] = $row['url'];
        }

        return $urls;
    }

    public function moveTo(int $linkId, string $url): void
    {
        $this->createQueryBuilder('link')
            ->update()
            ->set('link.url', ':url')
            ->andWhere('link.id = :id')
            ->setParameter('url', $url)
            ->setParameter('id', $linkId)
            ->getQuery()
            ->execute();
    }

    /** @return list<int|string|null> */
    private static function rowOf(NewSharedLinkModel $link): array
    {
        return [
            $link->preview->url,
            $link->urlHash,
            $link->preview->title,
            $link->preview->description,
            $link->preview->imageUrl,
            $link->preview->siteName,
            $link->language?->language->value,
            $link->language?->source->value,
            $link->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\SharedLink;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Operations\UrlCleanup\Model\StoredLinkAddressModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharedLink> */
final class SharedLinkRepository extends ServiceEntityRepository
{
    private const int ROWS_PER_INSERT = 100;
    private const array REFERENCES = [
        'link_card' => 'shared_link_id',
        'entry' => 'quoted_shared_link_id',
        'trending_story_entry' => 'shared_link_id',
    ];
    private const string UNREFERENCED
        = 'NOT EXISTS (SELECT 1 FROM link_card card WHERE card.shared_link_id = shared_link.id)'
        . ' AND NOT EXISTS (SELECT 1 FROM entry quoting WHERE quoting.quoted_shared_link_id = shared_link.id)';

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
     * The rows a writer is about to reference, their GC mark cleared before they are read: a sweep between the read
     * and the writer's flush can then only mark them again, and deletion needs a further grace.
     *
     * @param list<string> $urlHashes
     *
     * @return array<string, SharedLink> by url hash
     */
    public function claimByUrlHashes(array $urlHashes): array
    {
        if ($urlHashes === []) {
            return [];
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_link SET unreferenced_since = NULL WHERE unreferenced_since IS NOT NULL AND url_hash IN (?)',
            [array_values(array_unique($urlHashes))],
            [ArrayParameterType::STRING],
        );

        return $this->byUrlHashes($urlHashes);
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

    /**
     * Mark, then delete: a row is deleted only once it has stayed unreferenced for the grace, counted from the sweep
     * that first saw it unreferenced; a writer's claimByUrlHashes() clears the mark before it reads the row.
     *
     * @return int the rows deleted
     */
    public function sweepUnreferenced(\DateTimeImmutable $now, \DateTimeImmutable $markedBefore): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'UPDATE shared_link SET unreferenced_since = NULL WHERE unreferenced_since IS NOT NULL AND NOT ('
            . self::UNREFERENCED . ')',
        );
        $connection->executeStatement(
            'UPDATE shared_link SET unreferenced_since = ? WHERE unreferenced_since IS NULL AND '
            . self::UNREFERENCED,
            [$now->format('Y-m-d H:i:s')],
        );

        return (int) $connection->executeStatement(
            'DELETE FROM shared_link WHERE unreferenced_since < ? AND ' . self::UNREFERENCED,
            [$markedBefore->format('Y-m-d H:i:s')],
        );
    }

    /** @return list<SharedLink> rows without a language, in id order */
    public function unsetLanguageAfterId(int $afterId, int $limit): array
    {
        /** @var list<SharedLink> $links */
        $links = $this->createQueryBuilder('link')
            ->andWhere('link.language IS NULL')
            ->andWhere('link.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('link.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $links;
    }

    /** @return list<StoredLinkAddressModel> in id order */
    public function addressesAfterId(int $afterId, int $limit): array
    {
        /** @var list<array{id: int|string, url: string, urlHash: string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('link.id', 'link.url', 'link.urlHash')
            ->andWhere('link.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('link.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): StoredLinkAddressModel
                => new StoredLinkAddressModel((int) $row['id'], $row['url'], $row['urlHash']),
            $rows,
        );
    }

    /** Moves every reference of one link onto another that holds the same URL, then deletes the first. */
    public function mergeInto(int $mergedLinkId, int $holderLinkId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (self::REFERENCES as $table => $column) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column),
                [$holderLinkId, $mergedLinkId],
            );
        }
        $this->moveResolution($mergedLinkId, $holderLinkId);
        $connection->executeStatement(
            'UPDATE shared_link SET unreferenced_since = NULL WHERE id = ?',
            [$holderLinkId],
        );
        $connection->executeStatement('DELETE FROM shared_link WHERE id = ?', [$mergedLinkId]);
    }

    public function relocate(int $linkId, string $url, string $urlHash): void
    {
        $this->createQueryBuilder('link')
            ->update()
            ->set('link.url', ':url')
            ->set('link.urlHash', ':urlHash')
            ->andWhere('link.id = :id')
            ->setParameter('url', $url)
            ->setParameter('urlHash', $urlHash)
            ->setParameter('id', $linkId)
            ->getQuery()
            ->execute();
    }

    /** The holder keeps its own resolution; the merged link's goes with its row (ON DELETE CASCADE). */
    private function moveResolution(int $mergedLinkId, int $holderLinkId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $holderHasOne = $connection->fetchOne(
            'SELECT 1 FROM shared_link_resolution WHERE shared_link_id = ?',
            [$holderLinkId],
        ) !== false;
        if ($holderHasOne) {
            return;
        }
        $connection->executeStatement(
            'UPDATE shared_link_resolution SET shared_link_id = ? WHERE shared_link_id = ?',
            [$holderLinkId, $mergedLinkId],
        );
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

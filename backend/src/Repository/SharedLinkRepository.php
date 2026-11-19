<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\SharedLink;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharedLink> */
final class SharedLinkRepository extends ServiceEntityRepository
{
    private const int ROWS_PER_INSERT = 100;
    /** The columns that keep a link from the GC. */
    private const array OWNING_REFERENCES = ['link_card' => 'shared_link_id', 'entry' => 'quoted_shared_link_id'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedLink::class);
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
        )->rows($connection, array_map(self::buildRow(...), $links));
    }

    public function renameSite(int $linkId, string $from, string $to): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_link SET site_name = ? WHERE id = ? AND site_name = ?',
            [$to, $linkId, $from],
        );
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
            . self::unreferenced() . ')',
        );
        $connection->executeStatement(
            'UPDATE shared_link SET unreferenced_since = ? WHERE unreferenced_since IS NULL AND '
            . self::unreferenced(),
            [$now->format('Y-m-d H:i:s')],
        );

        return (int) $connection->executeStatement(
            'DELETE FROM shared_link WHERE unreferenced_since < ? AND ' . self::unreferenced(),
            [$markedBefore->format('Y-m-d H:i:s')],
        );
    }

    private static function unreferenced(): string
    {
        return implode(' AND ', array_map(
            static fn (string $table, string $column): string
                => \sprintf('NOT EXISTS (SELECT 1 FROM %s WHERE %s.%s = shared_link.id)', $table, $table, $column),
            array_keys(self::OWNING_REFERENCES),
            self::OWNING_REFERENCES,
        ));
    }

    /** @return list<int|string|null> */
    private static function buildRow(NewSharedLinkModel $link): array
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

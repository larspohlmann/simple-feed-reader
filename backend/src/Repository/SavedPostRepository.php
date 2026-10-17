<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryGuid;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Service\SavedPosts\Model\SavedCopyModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/** The rescue's queries (#1666): an INSERT … SELECT copies every entry column, so a column added later is copied too. */
final readonly class SavedPostRepository
{
    private const array POSTER_FIELDS = ['author.name', 'author.profileUrl', 'author.avatarUrl'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<int, ?string> */
    public function flaggedIn(int $userId, int $feedId): array
    {
        /** @var list<array{id: int, urlHash: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.location.urlHash AS urlHash')
            ->from(Entry::class, 'e')
            ->join(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('e.feed = :feed')
            ->andWhere('es.isFavorite = :flagged OR es.isKept = :flagged')
            ->setParameter('user', $userId)
            ->setParameter('feed', $feedId)
            ->setParameter('flagged', true, Types::BOOLEAN)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $flagged = [];
        foreach ($rows as $row) {
            $flagged[(int) $row['id']] = $row['urlHash'];
        }

        return $flagged;
    }

    /**
     * @param array<int, ?string> $urlHashesByEntryId
     * @param list<int>           $leavingFeedIds
     *
     * @return array<int, int>
     */
    public function keptTwinsOf(int $userId, array $urlHashesByEntryId, array $leavingFeedIds): array
    {
        $hashes = array_values(array_unique(array_filter(
            $urlHashesByEntryId,
            static fn (?string $hash): bool => null !== $hash,
        )));
        if ([] === $hashes) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.location.urlHash AS urlHash')
            ->from(Entry::class, 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.location.urlHash IN (:hashes)')
            ->setParameter('user', $userId)
            ->setParameter('hashes', $hashes)
            ->orderBy('e.id', 'ASC');
        if ([] !== $leavingFeedIds) {
            $qb->andWhere('e.feed NOT IN (:leaving)')->setParameter('leaving', $leavingFeedIds);
        }
        /** @var list<array{id: int, urlHash: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $twinByHash = [];
        foreach ($rows as $row) {
            $twinByHash[$row['urlHash']] ??= (int) $row['id'];
        }

        return self::byEntry($urlHashesByEntryId, $twinByHash);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, int>
     */
    public function copiesIn(int $savedFeedId, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{originalId: int, copyId: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('original.id AS originalId', 'held.id AS copyId')
            ->from(Entry::class, 'original')
            ->join(Entry::class, 'held', 'ON', 'held.guid.hash = original.guid.hash AND held.feed = :saved')
            ->andWhere('original.id IN (:entryIds)')
            ->setParameter('saved', $savedFeedId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        $copies = [];
        foreach ($rows as $row) {
            $copies[(int) $row['originalId']] = (int) $row['copyId'];
        }

        return $copies;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array{id: int, guid: string, guidHash: string, urlHash: ?string, feedId: int}>
     */
    public function originalsOf(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{id: int|string, guid: string, guidHash: string, urlHash: ?string, feedId: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.guid.value AS guid', 'e.guid.hash AS guidHash', 'e.location.urlHash AS urlHash')
            ->addSelect('IDENTITY(e.feed) AS feedId')
            ->from(Entry::class, 'e')
            ->andWhere('e.id IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'guid' => $row['guid'],
                'guidHash' => $row['guidHash'],
                'urlHash' => $row['urlHash'],
                'feedId' => (int) $row['feedId'],
            ],
            $rows,
        );
    }

    /**
     * @param list<string> $guidHashes
     *
     * @return array<string, array{id: int, urlHash: ?string}>
     */
    public function heldIn(int $savedFeedId, array $guidHashes): array
    {
        if ([] === $guidHashes) {
            return [];
        }
        /** @var list<array{id: int|string, guidHash: string, urlHash: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('held.id AS id', 'held.guid.hash AS guidHash', 'held.location.urlHash AS urlHash')
            ->from(Entry::class, 'held')
            ->andWhere('held.feed = :saved')
            ->andWhere('held.guid.hash IN (:guidHashes)')
            ->setParameter('saved', $savedFeedId)
            ->setParameter('guidHashes', $guidHashes)
            ->getQuery()
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            $held[$row['guidHash']] = ['id' => (int) $row['id'], 'urlHash' => $row['urlHash']];
        }

        return $held;
    }

    /**
     * Callers pass only entries the plan found unheld; the NOT EXISTS guard still keeps a concurrent copy out.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, int>
     */
    public function copyInto(SavedCopyModel $copy, array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        $held = $this->copiesIn($copy->savedFeedId, $entryIds);
        [$posterOverrides, $posterParameters] = $this->posterColumns($copy->poster);
        $this->entityManager->getConnection()->executeStatement(
            $this->copySql($posterOverrides),
            ['savedFeedId' => $copy->savedFeedId, 'entryIds' => $entryIds] + $posterParameters,
            ['entryIds' => ArrayParameterType::INTEGER],
        );

        return array_diff_key($this->copiesIn($copy->savedFeedId, $entryIds), $held);
    }

    public function copyRenamed(SavedCopyModel $copy, int $entryId, EntryGuid $guid): int
    {
        $metadata = $this->entityManager->getClassMetadata(Entry::class);
        [$posterOverrides, $posterParameters] = $this->posterColumns($copy->poster);
        $overrides = $posterOverrides + [
            $metadata->getColumnName('guid.value') => ':guid',
            $metadata->getColumnName('guid.hash') => ':guidHash',
        ];
        $this->entityManager->getConnection()->executeStatement(
            $this->copySql($overrides),
            [
                'savedFeedId' => $copy->savedFeedId,
                'entryIds' => [$entryId],
                'guid' => $guid->getValue(),
                'guidHash' => $guid->getHash(),
            ] + $posterParameters,
            ['entryIds' => ArrayParameterType::INTEGER],
        );

        return (int) $this->entityManager->createQueryBuilder()
            ->select('held.id')
            ->from(Entry::class, 'held')
            ->andWhere('held.feed = :saved')->setParameter('saved', $copy->savedFeedId)
            ->andWhere('held.guid.hash = :guidHash')->setParameter('guidHash', $guid->getHash())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param array<int, int> $copyByEntryId */
    public function copyCompanions(array $copyByEntryId, ?EntryOrigin $origin): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ($copyByEntryId as $entryId => $copyId) {
            $pair = ['copy' => $copyId, 'original' => $entryId];
            $connection->executeStatement(
                'INSERT INTO link_card (entry_id, position, url, title, description, image_url, site_name)'
                . ' SELECT :copy, position, url, title, description, image_url, site_name FROM link_card'
                . ' WHERE entry_id = :original',
                $pair,
            );
            $connection->executeStatement(
                'INSERT INTO entry_category (entry_id, category_id, position, label)'
                . ' SELECT :copy, category_id, position, label FROM entry_category WHERE entry_id = :original',
                $pair,
            );
            $this->recordOrigin($copyId, $origin);
        }
    }

    private function recordOrigin(int $copyId, ?EntryOrigin $origin): void
    {
        if (null === $origin) {
            return;
        }
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO saved_entry_origin (entry_id, title, favicon_url) VALUES (:copy, :title, :faviconUrl)',
            ['copy' => $copyId, 'title' => $origin->title, 'faviconUrl' => $origin->faviconUrl],
        );
    }

    /**
     * @return array{array<string, string>, array<string, ?string>} column => SQL expression, and its parameters;
     *                                                              empty when each entry keeps its own author
     */
    private function posterColumns(?EntryAuthor $poster): array
    {
        if (null === $poster) {
            return [[], []];
        }
        [$name, $url, $avatar] = array_map(
            $this->entityManager->getClassMetadata(Entry::class)->getColumnName(...),
            self::POSTER_FIELDS,
        );

        return [
            [
                $name => ':posterName',
                $url => ':posterUrl',
                $avatar => sprintf('COALESCE(e.%s, :posterAvatarUrl)', $avatar),
            ],
            [
                'posterName' => $poster->getName(),
                'posterUrl' => $poster->getProfileUrl(),
                'posterAvatarUrl' => $poster->getAvatarUrl(),
            ],
        ];
    }

    /**
     * Every entry column but its id is copied; an override replaces a column's value by an SQL expression. The guard
     * compares the guid hash the copy will carry, overridden or not. feed_id is a join column, not in the column list.
     *
     * @param array<string, string> $overrides
     */
    private function copySql(array $overrides): string
    {
        $metadata = $this->entityManager->getClassMetadata(Entry::class);
        $guidHash = $metadata->getColumnName('guid.hash');
        $skipped = [$metadata->getColumnName('id'), ...array_keys($overrides)];
        $copied = array_values(array_diff($metadata->getColumnNames(), $skipped));
        $sources = [
            ...array_map(static fn (string $column): string => 'e.' . $column, $copied),
            ...array_values($overrides),
        ];

        return sprintf(
            'INSERT INTO entry (feed_id, %s) SELECT :savedFeedId, %s FROM entry e WHERE e.id IN (:entryIds)'
            . ' AND NOT EXISTS (SELECT 1 FROM entry held WHERE held.feed_id = :savedFeedId AND held.%s = %s)',
            implode(', ', [...$copied, ...array_keys($overrides)]),
            implode(', ', $sources),
            $guidHash,
            $overrides[$guidHash] ?? 'e.' . $guidHash,
        );
    }

    /**
     * @param array<int, ?string> $urlHashesByEntryId
     * @param array<string, int>  $twinByHash
     *
     * @return array<int, int>
     */
    private static function byEntry(array $urlHashesByEntryId, array $twinByHash): array
    {
        $twins = [];
        foreach ($urlHashesByEntryId as $entryId => $hash) {
            if (null !== $hash && isset($twinByHash[$hash])) {
                $twins[$entryId] = $twinByHash[$hash];
            }
        }

        return $twins;
    }
}

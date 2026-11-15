<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Entity\QuotedPostContent;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;
use App\Repository\SharedLinkRepository;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

final class SharedLinkRepositoryTest extends DbTestCase
{
    public function testInsertMissingSkipsAStoredHash(): void
    {
        $links = $this->repository();

        $links->insertMissing([self::newLink('h1', 'First')]);
        $links->insertMissing([self::newLink('h1', 'Second'), self::newLink('h2', 'Other')]);

        $stored = $links->byUrlHashes(['h1', 'h2']);
        self::assertSame('First', $stored['h1']->preview()->title);
        self::assertSame('Other', $stored['h2']->preview()->title);
        self::assertSame(2, $this->storedRows());
    }

    public function testIdsAndRowsAreFoundByHash(): void
    {
        $links = $this->repository();
        $links->insertMissing([
            new NewSharedLinkModel(
                'h1',
                new LinkCardContent('https://example.com/h1', 'One', 'About', 'https://example.com/i.jpg', 'Ex'),
                LinkLanguage::stated(FeedLanguage::Spanish),
                new \DateTimeImmutable('2026-11-15 10:00:00'),
            ),
            self::newLink('h2', 'Two'),
        ]);

        $ids = $links->idsByUrlHash(['h1', 'h2', 'missing']);
        $found = $links->findOneByUrlHash('h1');

        self::assertSame(['h1', 'h2'], array_keys($ids));
        self::assertInstanceOf(SharedLink::class, $found);
        self::assertSame($ids['h1'], $found->requireId());
        self::assertSame('h1', $found->getUrlHash());
        self::assertSame(FeedLanguage::Spanish, $found->getLanguage());
        self::assertEquals(
            new LinkCardContent('https://example.com/h1', 'One', 'About', 'https://example.com/i.jpg', 'Ex'),
            $found->preview(),
        );
        self::assertNull($links->findOneByUrlHash('missing'));
    }

    public function testFindingNoHashesIsEmpty(): void
    {
        self::assertSame([], $this->repository()->byUrlHashes([]));
        self::assertSame([], $this->repository()->idsByUrlHash([]));
    }

    public function testInsertingNothingWritesNothing(): void
    {
        $this->repository()->insertMissing([]);

        self::assertSame(0, $this->storedRows());
    }

    public function testDeletingAStillReferencedRowIsRefused(): void
    {
        $feed = new Feed('https://example.com/rss');
        $entry = new Entry(
            $feed,
            'carded',
            null,
            'Post',
            new \DateTimeImmutable('2026-11-15 10:00:00'),
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $link = SharedLinks::of(
            $this->entityManager,
            new LinkCardContent('https://example.com/a', 'A', null, null, 'example.com'),
        );
        $this->entityManager->persist(new LinkCard($entry, 0, $link));
        $this->entityManager->flush();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM shared_link WHERE id = ?',
            [$link->requireId()],
        );
    }

    public function testUnreferencedRowsPastTheGraceAreDeleted(): void
    {
        $links = $this->repository();
        $links->insertMissing([
            self::linkCreated('carded', '2026-11-13 10:00:00'),
            self::linkCreated('quoted', '2026-11-13 10:00:00'),
            self::linkCreated('stale', '2026-11-13 10:00:00'),
            self::linkCreated('fresh', '2026-11-15 09:00:00'),
        ]);
        $stored = $links->byUrlHashes(['carded', 'quoted']);
        $entry = $this->entry('referencing');
        $entry->shareQuotedPost(
            new QuotedPostContent('https://q.example/1', 'Q', 'q.example', null, null, '', [], null),
            $stored['quoted'],
        );
        $this->entityManager->persist(new LinkCard($entry, 0, $stored['carded']));
        $this->entityManager->flush();

        $deleted = $links->deleteUnreferencedCreatedBefore(new \DateTimeImmutable('2026-11-14 10:00:00'));

        self::assertSame(1, $deleted);
        self::assertSame(['carded', 'fresh', 'quoted'], $this->storedHashes());
    }

    public function testTheGraceBoundaryIsExclusive(): void
    {
        $this->repository()->insertMissing([self::linkCreated('edge', '2026-11-14 10:00:00')]);

        $deleted = $this->repository()->deleteUnreferencedCreatedBefore(new \DateTimeImmutable('2026-11-14 10:00:00'));

        self::assertSame(0, $deleted);
    }

    private static function newLink(string $hash, string $title): NewSharedLinkModel
    {
        return new NewSharedLinkModel(
            $hash,
            new LinkCardContent('https://example.com/' . $hash, $title, null, null, 'example.com'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
    }

    private static function linkCreated(string $hash, string $createdAt): NewSharedLinkModel
    {
        return new NewSharedLinkModel(
            $hash,
            new LinkCardContent('https://example.com/' . $hash, $hash, null, null, 'example.com'),
            null,
            new \DateTimeImmutable($createdAt),
        );
    }

    private function entry(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.rss');
        $entry = new Entry(
            $feed,
            $guid,
            null,
            'Post',
            new \DateTimeImmutable('2026-11-15 10:00:00'),
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /** @return list<mixed> */
    private function storedHashes(): array
    {
        return $this->entityManager->getConnection()
            ->fetchFirstColumn('SELECT url_hash FROM shared_link ORDER BY url_hash');
    }

    private function storedRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shared_link');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function repository(): SharedLinkRepository
    {
        $repository = self::getContainer()->get(SharedLinkRepository::class);
        self::assertInstanceOf(SharedLinkRepository::class, $repository);

        return $repository;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\Feed;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Entity\SavedEntryOrigin;
use App\Enum\ShareKind;
use App\Enum\SourceFormat;
use App\Repository\EntryBatchInserter;
use App\Repository\LinkCardRepository;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Tests\DbTestCase;

final class EntryBatchInserterTest extends DbTestCase
{
    /**
     * Sentinel default for entryLine()'s $url: distinguishes "not given"
     * (generate one from the guid) from an explicit `null`, which `??`
     * cannot do.
     */
    private const string GENERATED_URL = "\0generated";

    private function createFeed(string $url): int
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed->requireId();
    }

    /** @param list<LinkCardContent> $linkCards */
    private function entryLine(
        string $guid,
        string $title = 'Entry',
        ?string $url = self::GENERATED_URL,
        ?string $imageUrl = null,
        bool $titleDerived = false,
        ?string $shareKind = null,
        array $linkCards = [],
        ?QuotedPostContent $quote = null,
        ?EntryOrigin $origin = null,
    ): EntryLine {
        return new EntryLine(
            feedUrl: 'https://batch.example/feed.xml',
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: $url === self::GENERATED_URL ? 'https://batch.example/' . $guid : $url,
            title: $title,
            author: 'Ann Author',
            summary: 'sum',
            contentHtml: '<p>body</p>',
            imageUrl: $imageUrl,
            imageWidth: 640,
            imageHeight: null,
            publishedAt: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            createdAt: new \DateTimeImmutable('2026-08-02T00:00:00+00:00'),
            effectiveDate: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            titleDerived: $titleDerived,
            shareKind: $shareKind,
            linkCards: $linkCards,
            quote: $quote,
            origin: $origin,
        );
    }

    private function inserter(): EntryBatchInserter
    {
        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);

        return $inserter;
    }

    private function pendingImageVerificationRepository(): PendingImageVerificationRepository
    {
        $repository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertInstanceOf(PendingImageVerificationRepository::class, $repository);

        return $repository;
    }

    public function testInsertsMoreRowsThanOneStatementHolds(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $lines = [];
        for ($index = 0; $index < 501; ++$index) {
            $lines[] = $this->entryLine('guid-' . $index, 'Entry ' . $index);
        }

        $this->inserter()->insert($feedId, $lines);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feedId]);
        self::assertCount(501, $rows);
    }

    public function testARowRoundTripsFieldForFieldThroughTheOrm(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');

        $this->inserter()->insert($feedId, [$this->entryLine('one-guid', 'One')]);

        $this->entityManager->clear();
        $entry = $this->entityManager
            ->getRepository(Entry::class)
            ->findOneBy(['guid.hash' => hash('sha256', 'one-guid')]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('one-guid', $entry->getGuid());
        self::assertSame('https://batch.example/one-guid', $entry->getUrl());
        self::assertSame('One', $entry->getTitle());
        self::assertSame('Ann Author', $entry->getAuthor());
        self::assertSame('sum', $entry->getSummary());
        self::assertSame('<p>body</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(640, $entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
        self::assertSame('2026-08-01 10:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-02 00:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 10:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testARestoredRowKeepsWhatItShares(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');

        $this->inserter()->insert($feedId, [
            $this->entryLine('media-guid', shareKind: 'media'),
            $this->entryLine('plain-guid'),
        ]);

        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(Entry::class);
        $media = $repository->findOneBy(['guid.hash' => hash('sha256', 'media-guid')]);
        $plain = $repository->findOneBy(['guid.hash' => hash('sha256', 'plain-guid')]);
        self::assertInstanceOf(Entry::class, $media);
        self::assertInstanceOf(Entry::class, $plain);
        self::assertSame(ShareKind::Media, $media->getShareKind());
        self::assertNull($plain->getShareKind());
    }

    public function testARestoredRowKeepsItsQuotedPost(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $quote = new QuotedPostContent(
            'https://bsky.app/profile/q.bsky.social/post/3k',
            'Quoted Person',
            'q.bsky.social',
            'https://cdn.example/q.jpg',
            new \DateTimeImmutable('2026-10-13 21:07:00', new \DateTimeZone('UTC')),
            "quoted\ntext",
            [new EntryMedium('https://cdn.example/1.jpg', 'image', 1200, 675)],
            new QuotedPostReference('https://bsky.app/profile/n/post/9', 'n.bsky.social'),
        );

        $this->inserter()->insert($feedId, [
            $this->entryLine('quote-guid', shareKind: 'quote', quote: $quote),
            $this->entryLine('plain-guid'),
        ]);

        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(Entry::class);
        $quoting = $repository->findOneBy(['guid.hash' => hash('sha256', 'quote-guid')]);
        $plain = $repository->findOneBy(['guid.hash' => hash('sha256', 'plain-guid')]);
        self::assertInstanceOf(Entry::class, $quoting);
        self::assertInstanceOf(Entry::class, $plain);
        self::assertEquals($quote, $quoting->getQuotedPost());
        self::assertSame(ShareKind::Quote, $quoting->getShareKind());
        self::assertNull($plain->getQuotedPost());
    }

    public function testARestoredQuoteWithoutANestedQuoteKeepsNone(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $quote = new QuotedPostContent('https://bsky.app/profile/q/post/3k', 'Q', 'q', null, null, 'hi', [], null);

        $this->inserter()->insert($feedId, [$this->entryLine('quote-guid', shareKind: 'quote', quote: $quote)]);

        $this->entityManager->clear();
        $quoting = $this->entityManager->getRepository(Entry::class)
            ->findOneBy(['guid.hash' => hash('sha256', 'quote-guid')]);
        self::assertInstanceOf(Entry::class, $quoting);
        self::assertEquals($quote, $quoting->getQuotedPost());
        self::assertNull($quoting->getQuotedPost()?->nestedQuote);
    }

    public function testARestoredRowKeepsWhetherItsTitleWasDerived(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');

        $this->inserter()->insert($feedId, [
            $this->entryLine('derived-guid', titleDerived: true),
            $this->entryLine('given-guid'),
        ]);

        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(Entry::class);
        $derived = $repository->findOneBy(['guid.hash' => hash('sha256', 'derived-guid')]);
        $given = $repository->findOneBy(['guid.hash' => hash('sha256', 'given-guid')]);
        self::assertInstanceOf(Entry::class, $derived);
        self::assertInstanceOf(Entry::class, $given);
        self::assertTrue($derived->isTitleDerived());
        self::assertFalse($given->isTitleDerived());
    }

    public function testAnEmptyListDoesNothing(): void
    {
        $this->inserter()->insert(999, []);

        $this->addToAssertionCount(1);
    }

    public function testARestoredImageIsNotQueuedForVerification(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');

        $this->inserter()->insert($feedId, [
            $this->entryLine('with-image', imageUrl: 'https://batch.example/image.jpg'),
        ]);

        self::assertSame([], $this->pendingImageVerificationRepository()->findPendingImageVerification(10));
    }

    public function testRecomputesTheStableUrlHashForEveryInsertedRow(): void
    {
        $feedId = $this->createFeed('https://hash.example/feed.xml');
        $this->inserter()->insert($feedId, [
            $this->entryLine(guid: 'a', url: 'https://hash.example/one?utm_source=rss'),
            $this->entryLine(guid: 'b', url: null),
        ]);

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT guid, url_hash FROM entry WHERE feed_id = ? ORDER BY guid',
            [$feedId],
        );

        self::assertSame(
            hash('sha256', 'https://hash.example/one'),
            $rows[0]['url_hash'],
            'A decorated URL must hash to its normalised form, exactly as ingest hashes it.',
        );
        self::assertNull($rows[1]['url_hash'], 'A url-less entry dedupes on guid alone.');
    }

    public function testInsertsTheLinkCardsOfTheEntriesItWasGiven(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $withCards = $this->entryLine('with-cards', linkCards: [
            new LinkCardContent('https://example.com/a', 'A', 'About A', 'https://example.com/a.jpg', 'Example'),
            new LinkCardContent('https://example.com/b', 'B', null, null, 'example.com'),
        ]);
        $without = $this->entryLine('without-cards');
        $this->inserter()->insert($feedId, [$withCards, $without]);
        $entryIdsByGuidHash = $this->entryIdsByGuidHash($feedId);

        $this->inserter()->insertLinkCards($entryIdsByGuidHash, [$withCards, $without]);

        $linkCards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $linkCards);
        $stored = $linkCards->contentsByEntryIds(array_values($entryIdsByGuidHash));
        self::assertEquals($withCards->linkCards, $stored[$entryIdsByGuidHash[$withCards->guidHash]]);
        self::assertArrayNotHasKey($entryIdsByGuidHash[$without->guidHash], $stored);
    }

    public function testStoresTheOriginOfASavedFeedsEntry(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $line = $this->entryLine('saved-copy', origin: new EntryOrigin('Source', 'https://source.example/favicon.ico'));
        $this->inserter()->insert($feedId, [$line]);

        $this->inserter()->insertOrigins($this->entryIdsByGuidHash($feedId), [$line], SourceFormat::SAVED);

        self::assertSame(1, $this->entityManager->getRepository(SavedEntryOrigin::class)->count([]));
    }

    public function testDropsACraftedOriginOnAnOrdinaryFeedsEntry(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $line = $this->entryLine('crafted', origin: new EntryOrigin('Source', 'https://evil.example/favicon.ico'));
        $this->inserter()->insert($feedId, [$line]);

        $this->inserter()->insertOrigins($this->entryIdsByGuidHash($feedId), [$line], SourceFormat::XML);

        self::assertSame(0, $this->entityManager->getRepository(SavedEntryOrigin::class)->count([]));
    }

    /** @return array<string, int> */
    private function entryIdsByGuidHash(int $feedId): array
    {
        $ids = [];
        foreach ($this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feedId]) as $entry) {
            $ids[$entry->getGuidHash()] = $entry->requireId();
        }

        return $ids;
    }
}

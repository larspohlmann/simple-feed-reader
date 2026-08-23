<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\ParsedImage;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;

final class EntryIngestorTest extends DbTestCase
{
    private EntryIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
        );
    }

    private function parsedEntryAt(string $guid, string $url): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: $url,
            title: 'Title',
            author: null,
            summary: null,
            contentHtml: '<p>body</p>',
            publishedAt: null,
        );
    }

    /**
     * BBC appends a revision counter to its GUID (`…#0`, `…#1`, …) while the
     * article URL stays stable, so a re-fetch of the same article carries a new
     * GUID hash. Keying dedup on the stable URL is what stops the second row.
     */
    public function testARevisedGuidWithTheSameUrlDoesNotCreateASecondRow(): void
    {
        $feed = $this->feed();
        $url = 'https://www.bbc.com/news/articles/ckg4424zd7go';

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('https://www.bbc.com/news/articles/ckg4424zd7go#0', $url),
        ]), self::context());
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('https://www.bbc.com/news/articles/ckg4424zd7go#1', $url),
        ]), self::context());
        $this->em->flush();

        self::assertCount(0, $created);
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testTrackingParametersThatVaryPerFetchDoNotDefeatDedup(): void
    {
        $feed = $this->feed();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('guid-a', 'https://www.bbc.com/news/x?at_medium=RSS&at_campaign=rss'),
        ]), self::context());
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('guid-b', 'https://www.bbc.com/news/x?at_medium=email'),
        ]), self::context());
        $this->em->flush();

        self::assertCount(0, $created);
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    /**
     * A whole feed refreshes at once: several already-stored articles come back
     * in one fetch, each with a bumped revision GUID. Every one of them must be
     * recognized by its stable URL, not just the first — the dedup preloads and
     * matches the batch's full set of URL hashes.
     */
    public function testRefetchingSeveralStoredArticlesWithRevisedGuidsCreatesNoNewRows(): void
    {
        $feed = $this->feed();
        $first = 'https://www.bbc.com/news/articles/aaa';
        $second = 'https://www.bbc.com/news/articles/bbb';

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('aaa#0', $first),
            $this->parsedEntryAt('bbb#0', $second),
        ]), self::context());
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('aaa#1', $first),
            $this->parsedEntryAt('bbb#1', $second),
        ]), self::context());
        $this->em->flush();

        self::assertCount(0, $created);
        self::assertCount(2, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testTwoItemsWithTheSameUrlInOneBatchCreateOnlyOneRow(): void
    {
        $feed = $this->feed();
        $url = 'https://www.bbc.com/news/articles/abc';

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('abc#0', $url),
            $this->parsedEntryAt('abc#1', $url),
        ]), self::context());
        $this->em->flush();

        self::assertCount(1, $created);
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testDifferentUrlsWithDifferentGuidsRemainSeparate(): void
    {
        $feed = $this->feed();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntryAt('g1', 'https://example.com/story?id=42'),
            $this->parsedEntryAt('g2', 'https://example.com/story?id=43'),
        ]), self::context());
        $this->em->flush();

        self::assertCount(2, $created);
        self::assertCount(2, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testUrlLessEntriesStillDedupeByGuid(): void
    {
        $feed = $this->feed();
        $item = new ParsedEntry('only-guid', null, 'Title', null, null, '<p>body</p>', null);

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [$item]), self::context());
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [$item]), self::context());
        $this->em->flush();

        self::assertCount(0, $created);
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private static function fetchedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-21T12:00:00Z');
    }

    /**
     * A first-fetch context (no previous fetch), which is all these ingestion
     * mechanics tests need — the effective-date policy itself is exercised by
     * testAnArticleTheFeedAlreadyServedKeepsItsPublishedDate() below.
     */
    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(self::fetchedAt(), null);
    }

    private function effectiveDateOf(Feed $feed, string $guid): string
    {
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed, 'guid' => $guid]);
        self::assertNotNull($entry);

        return $entry->getEffectiveDate()->format('Y-m-d H:i:s');
    }

    private function parsedEntryWithImage(string $guid, ?ParsedImage $image): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: null,
            title: 'Title',
            author: null,
            summary: null,
            contentHtml: '<p>body</p>',
            publishedAt: null,
            image: $image,
        );
    }

    private function parsedEntry(string $guid, string $title, ?string $contentHtml = null): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: $title,
            author: 'Author',
            summary: '<p>A &amp; B summary</p>',
            contentHtml: $contentHtml ?? '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-07-20 08:00:00'),
        );
    }

    public function testAllEntriesInOneIngestCallShareTheFetchedAtTimestamp(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $fetchedAt = new \DateTimeImmutable('2026-07-21T12:00:00Z');
        $parsed = new ParsedFeed(null, null, null, null, [
            $this->parsedEntry('g1', 'One'),
            $this->parsedEntry('g2', 'Two'),
            $this->parsedEntry('g3', 'Three'),
        ]);

        $this->ingestor->ingest($feed, $parsed, new FeedIngestContext($fetchedAt, null));
        $this->em->flush();

        $entries = $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]);
        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertSame($fetchedAt->getTimestamp(), $entry->getCreatedAt()->getTimestamp());
        }
    }

    public function testIngestsNewEntriesSanitizedAndDeduped(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $parsed = new ParsedFeed('Feed Title', 'https://example.com/', 'Desc', null, [
            $this->parsedEntry('g1', 'One'),
            $this->parsedEntry('g2', 'Two'),
            $this->parsedEntry('g1', 'Duplicate of one'),
        ]);

        $created = $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        self::assertCount(2, $created);
        $entries = $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]);
        self::assertCount(2, $entries);

        $first = $entries[0];
        self::assertStringNotContainsString('script', (string) $first->getContentHtml());
        self::assertSame('A & B summary', $first->getSummary());
        self::assertSame('Feed Title', $feed->getTitle());
        self::assertSame('https://example.com/', $feed->getSiteUrl());
    }

    public function testSecondIngestOnlyAddsUnseenGuids(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntry('g1', 'One'),
        ]), self::context());
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, [
            $this->parsedEntry('g1', 'One again'),
            $this->parsedEntry('g3', 'Three'),
        ]), self::context());
        $this->em->flush();

        self::assertCount(1, $created);
        self::assertCount(2, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testSameGuidInDifferentFeedsAreSeparateEntries(): void
    {
        $feedA = new Feed('https://a.example.com/feed');
        $feedB = new Feed('https://b.example.com/feed');
        $this->em->persist($feedA);
        $this->em->persist($feedB);
        $this->em->flush();

        $parsed = new ParsedFeed(null, null, null, null, [$this->parsedEntry('shared-guid', 'Shared')]);
        self::assertCount(1, $this->ingestor->ingest($feedA, $parsed, self::context()));
        self::assertCount(1, $this->ingestor->ingest($feedB, $parsed, self::context()));
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feedA]));
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feedB]));
    }

    public function testOverlongFieldsAreTruncatedToColumnLimits(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $parsed = new ParsedFeed(str_repeat('T', 900), null, null, null, [
            new ParsedEntry(
                guid: 'long',
                url: 'https://example.com/' . str_repeat('u', 3000),
                title: str_repeat('t', 2000),
                author: str_repeat('a', 500),
                summary: str_repeat('s', 2000),
                contentHtml: '<p>ok</p>',
                publishedAt: null,
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertNotNull($entry);
        self::assertSame(1024, mb_strlen($entry->getTitle()));
        self::assertSame(255, mb_strlen((string) $entry->getAuthor()));
        self::assertSame(2048, mb_strlen((string) $entry->getUrl()));
        self::assertLessThanOrEqual(500, mb_strlen((string) $entry->getSummary()));
        self::assertSame(512, mb_strlen((string) $feed->getTitle()));
    }

    public function testPersistsTheFeedSuppliedImage(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('T', null, null, null, [
            new ParsedEntry(
                guid: 'g1',
                url: 'https://x/1',
                title: 'One',
                author: null,
                summary: null,
                contentHtml: '<p>body</p>',
                publishedAt: null,
                image: new ParsedImage('https://i/1.jpg', 948, 474),
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g1']);
        self::assertNotNull($entry);
        self::assertSame('https://i/1.jpg', $entry->getImageUrl());
        self::assertSame(948, $entry->getImageWidth());
        self::assertSame(474, $entry->getImageHeight());
    }

    public function testMissingImageLeavesTheColumnsNull(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('T', null, null, null, [
            new ParsedEntry('no-image', 'https://x/1', 'One', null, null, '<p>body</p>', null, null),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'no-image']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
        self::assertNull($entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
    }

    public function testAnImageUrlOverTheColumnLimitIsDroppedRatherThanTruncated(): void
    {
        $feed = $this->feed();
        $overlongUrl = 'https://i/' . str_repeat('u', 2048) . '.jpg';
        $parsed = new ParsedFeed('T', null, null, null, [
            new ParsedEntry(
                guid: 'overlong-image',
                url: 'https://x/1',
                title: 'One',
                author: null,
                summary: null,
                contentHtml: '<p>body</p>',
                publishedAt: null,
                image: new ParsedImage($overlongUrl, 100, 100),
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'overlong-image']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
        self::assertNull($entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
    }

    public function testProtocolRelativeImageUrlIsUpgradedToHttpsAndKept(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('protocol-relative', new ParsedImage('//i.example.com/img.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'protocol-relative']);
        self::assertNotNull($entry);
        self::assertSame('https://i.example.com/img.jpg', $entry->getImageUrl());
    }

    public function testHttpImageUrlIsDroppedAsMixedContent(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('http-image', new ParsedImage('http://i.example.com/img.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'http-image']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testDataUriImageIsDropped(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('data-uri-image', new ParsedImage('data:image/png;base64,AAAA', null, null)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'data-uri-image']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testSiteRelativeImageUrlIsDropped(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, null, [
            $this->parsedEntryWithImage('site-relative-image', new ParsedImage('/img/x.jpg', 400, 300)),
        ]), self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'site-relative-image']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testSummaryFallsBackToContentHtmlWhenFeedSummaryIsNull(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('T', null, null, null, [
            new ParsedEntry(
                guid: 'fallback-summary',
                url: null,
                title: 'One',
                author: null,
                summary: null,
                contentHtml: '<p>Body text here.</p>',
                publishedAt: null,
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'fallback-summary']);
        self::assertNotNull($entry);
        self::assertSame('Body text here.', $entry->getSummary());
    }

    public function testJunkContentHtmlYieldsNullSummaryNotTheJunkToken(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('T', null, null, null, [
            new ParsedEntry(
                guid: 'junk-summary',
                url: null,
                title: 'One',
                author: null,
                summary: null,
                // @lang TEXT: the `alt`-less image is the input under test — the
                // ingestor has to reject this as a summary — so it stays as is.
                contentHtml: /** @lang TEXT */ '<a href="https://x"><img src="https://i/a.jpg"/></a> None',
                publishedAt: null,
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, self::context());
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'junk-summary']);
        self::assertNotNull($entry);
        self::assertNull($entry->getSummary());
    }

    public function testEmptyParsedFeedStillUpdatesMetadata(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed('New Title', null, null, null, []), self::context());

        self::assertCount(0, $created);
        self::assertSame('New Title', $feed->getTitle());
    }

    public function testNullMetadataDoesNotWipeExistingFeedFields(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setTitle('Existing Title');
        $feed->setSiteUrl('https://existing.example.com/');
        $this->em->persist($feed);
        $this->em->flush();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, null, []), self::context());

        self::assertSame('Existing Title', $feed->getTitle());
        self::assertSame('https://existing.example.com/', $feed->getSiteUrl());
    }

    public function testAFetchStoresTheFeedImage(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('Example', 'https://example.com/', 'Desc', 'https://example.com/logo.png', []);

        $this->ingestor->ingest($feed, $parsed, self::context());

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }

    public function testALaterFetchWithoutAnImageKeepsTheStoredOne(): void
    {
        $feed = $this->feed();
        $feed->setImageUrl('https://example.com/logo.png');
        $parsed = new ParsedFeed('Example', 'https://example.com/', 'Desc', null, []);

        $this->ingestor->ingest($feed, $parsed, self::context());

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }

    public function testAnArticleTheFeedAlreadyServedKeepsItsPublishedDate(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed(null, null, null, null, [
            new ParsedEntry(
                guid: 'old',
                url: 'https://example.com/old',
                title: 'Old',
                author: null,
                summary: null,
                contentHtml: '<p>body</p>',
                publishedAt: new \DateTimeImmutable('2020-03-01 00:00:00'),
            ),
            new ParsedEntry(
                guid: 'new',
                url: 'https://example.com/new',
                title: 'New',
                author: null,
                summary: null,
                contentHtml: '<p>body</p>',
                publishedAt: new \DateTimeImmutable('2026-08-14 07:30:00'),
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed, new FeedIngestContext(
            new \DateTimeImmutable('2026-08-14 12:00:00'),
            new \DateTimeImmutable('2026-08-14 06:00:00'),
        ));
        $this->em->flush();

        self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'old'));
        self::assertSame('2026-08-14 12:00:00', $this->effectiveDateOf($feed, 'new'));
    }
}

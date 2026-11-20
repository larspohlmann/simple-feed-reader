<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Subscription;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\EntryStateRepository;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Service\Library\Subscription\FirstFetchRecorder;
use App\Service\Sourcing\Discovery\Model\DiscoveredFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Service\Library\Search\RecordingSearchIndexWriter;
use App\Tests\Support\CopyReadStatePropagators;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class FirstFetchRecorderTest extends DbTestCase
{
    use SeedsUsers;

    private FirstFetchRecorder $recorder;
    private RecordingSearchIndexWriter $indexWriter;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new MockClock('2026-06-01T00:00:00Z');
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->recorder = new FirstFetchRecorder(
            EntryIngestors::build($this->entityManager, $clock),
            FeedSchedulers::build($clock),
            $this->entityManager,
            $clock,
            EntryIndexers::with($this->indexWriter),
            PostEnrichers::queue($this->entityManager, $clock),
            CopyReadStatePropagators::build($this->entityManager, $clock),
        );
    }

    public function testAFirstFetchStoresTheArticlesOwnPublicationDates(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'a'));
    }

    public function testAFirstFetchQueuesItsBlueskyPostsAndItsLinkPosts(): void
    {
        $feed = $this->feed();
        $post = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
        $discovered = $this->discovered($feed, [
            $this->parsedEntry($post, new \DateTimeImmutable('2026-10-10 16:01:00')),
            $this->parsedEntry('article', new \DateTimeImmutable('2026-10-10 15:00:00')),
            new ParsedEntryModel(
                guid: 'link-post',
                url: 'https://mastodon.example/@a/1',
                headline: Headline::derived('Worth a read'),
                author: null,
                summary: null,
                contentHtml: '<p>Worth a read: https://example.com/story</p>',
                publishedAt: new \DateTimeImmutable('2026-10-10 14:00:00'),
            ),
        ]);

        $this->recorder->record($feed, $discovered);

        $kinds = [];
        foreach ($this->entityManager->getRepository(ShareEnrichment::class)->findAll() as $pending) {
            $kinds[$pending->getEntry()->getGuid()] = $pending->getKind();
        }
        self::assertEqualsCanonicalizing(
            [$post => ShareEnrichmentKind::AppView, 'link-post' => ShareEnrichmentKind::LinkCards],
            $kinds,
        );
    }

    public function testAFirstFetchStoresTheDiscoveredCacheValidators(): void
    {
        $feed = $this->feed();
        $discovered = new DiscoveredFeedModel(
            $feed->getUrl(),
            new ParsedFeedModel('Discovered', null, null, null, []),
            '"v1"',
            'Mon, 20 Jul 2026 08:30:00 GMT',
        );

        $this->recorder->record($feed, $discovered);

        self::assertSame('"v1"', $feed->getEtag());
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $feed->getLastModified());
    }

    /**
     * EntryIngestor never flushes, so an entry has no id before record()'s flush: the index must get the id the
     * database assigned, which calling index() anywhere inside record() would not ensure.
     */
    public function testIndexesTheFirstFetchEntriesWithTheirRealIdsAfterFlush(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        $entry = $this->findByGuid($feed, 'a');
        self::assertNotNull($entry);
        self::assertNotNull($entry->getId());

        self::assertSame(['configure', 'upsert'], $this->indexWriter->calls);
        self::assertCount(1, $this->indexWriter->upserts);
        self::assertSame($entry->getId(), $this->indexWriter->upserts[0][0]->id);
    }

    public function testAFirstFetchStoresAtMostTwoHundredEntries(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250))));
    }

    public function testTheFirstFetchKeepsTheNewestEntries(): void
    {
        $feed = $this->feed();

        $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250)));

        self::assertNull($this->findByGuid($feed, 'guid-0'));
        self::assertNotNull($this->findByGuid($feed, 'guid-249'));
    }

    /** Far under the cap and still newest first: fails if newest() ever skips the sort for a small feed. */
    public function testASmallFeedIsStillSortedNewestFirst(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('older', new \DateTimeImmutable('2020-01-01 00:00:00')),
            $this->parsedEntry('newer', new \DateTimeImmutable('2021-01-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame(['newer', 'older'], $this->guidsByInsertionOrder($feed));
    }

    /** Entries sharing a publication date keep the feed's own order, as a batch-publishing source expects. */
    public function testTiedPublicationDatesKeepTheFeedsOwnOrder(): void
    {
        $feed = $this->feed();
        $same = new \DateTimeImmutable('2026-01-01 00:00:00');
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('b', $same),
            $this->parsedEntry('a', $same),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame(['b', 'a'], $this->guidsByInsertionOrder($feed));
    }

    public function testExactlyTwoHundredEntriesAreAllStored(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(200))));
    }

    public function testACopyOfAPostAlreadyReadArrivesReadAndOutOfTheUnreadCount(): void
    {
        $reader = $this->user('first-fetch-copy@example.com');
        $subscriptions = new NetworkFeedSubscriptions($this->entityManager);
        $local = $subscriptions->subscribe($reader, LemmyPostCopies::LOCAL_FEED)->getFeed();
        $readCopy = LemmyPostCopies::persistCopy($this->entityManager, $local, 'local-copy');
        $state = new EntryState($reader, $readCopy);
        $state->hide(new \DateTimeImmutable('2026-05-31T00:00:00Z'));
        $this->entityManager->persist($state);
        $all = $subscriptions->subscribe($reader, LemmyPostCopies::ALL_FEED)->getFeed();
        $lateCopy = $this->parsedEntry('all-copy', new \DateTimeImmutable('2026-05-30 00:00:00'))
            ->asDiscussionThread(Discussion::platformThread(LemmyPostEntries::PAGE));

        $unread = $this->recorder->record($all, $this->discovered($all, [
            $lateCopy,
            $this->parsedEntry('unrelated', new \DateTimeImmutable('2026-05-29 00:00:00')),
        ]));

        self::assertSame(1, $unread);
        $this->entityManager->clear();
        $copy = $this->findByGuid($all, 'all-copy');
        self::assertNotNull($copy);
        $copyState = $this->requireEntryStates()->findOneForUserEntry($reader->requireId(), $copy->requireId());
        self::assertNotNull($copyState);
        self::assertTrue($copyState->isHidden());
        self::assertFalse($copyState->isViewed());
    }

    private function requireEntryStates(): EntryStateRepository
    {
        /** @var EntryStateRepository $states */
        $states = $this->entityManager->getRepository(EntryState::class);

        return $states;
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /** @param list<ParsedEntryModel> $entries */
    private function discovered(Feed $feed, array $entries, ?string $imageUrl = null): DiscoveredFeedModel
    {
        return new DiscoveredFeedModel(
            $feed->getUrl(),
            new ParsedFeedModel('Discovered', null, null, $imageUrl, $entries),
        );
    }

    private function parsedEntry(string $guid, ?\DateTimeImmutable $publishedAt): ParsedEntryModel
    {
        return new ParsedEntryModel(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            headline: Headline::fromFeed('Title ' . $guid),
            author: null,
            summary: null,
            contentHtml: '<p>Body.</p>',
            publishedAt: $publishedAt,
        );
    }

    /** @return list<ParsedEntryModel> */
    private function parsedEntries(int $count): array
    {
        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $entries[] = $this->parsedEntry(
                'guid-' . $index,
                new \DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d minutes', $index)),
            );
        }

        return $entries;
    }

    private function effectiveDateOf(Feed $feed, string $guid): string
    {
        $entry = $this->findByGuid($feed, $guid);
        self::assertNotNull($entry);

        return $entry->getEffectiveDate()->format('Y-m-d H:i:s');
    }

    private function findByGuid(Feed $feed, string $guid): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy([
            'feed' => $feed,
            'guid.hash' => hash('sha256', $guid),
        ]);

        return $entry;
    }

    /**
     * The order EntryIngestor persisted in, by auto-increment id: the repository's (effectiveDate, id) display order
     * would mask a wrong ingest order.
     *
     * @return list<string>
     */
    private function guidsByInsertionOrder(Feed $feed): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Entry $entry): string => $entry->getGuid(), $entries);
    }

    public function testWithEntriesCopiesEveryFeedField(): void
    {
        $document = new ParsedFeedModel(
            'Example',
            'https://example.com/',
            'Example feed',
            'https://example.com/logo.png',
            [],
        );

        $capped = $document->withEntries([]);

        self::assertSame('https://example.com/logo.png', $capped->imageUrl);
        self::assertSame('Example', $capped->title);
        self::assertSame('https://example.com/', $capped->siteUrl);
        self::assertSame('Example feed', $capped->description);
    }

    /**
     * Drives the wired recorder's own cap with 250 entries and an image, so a newest() that rebuilds ParsedFeedModel
     * field by field instead of calling withEntries() loses the image on the persisted Feed.
     */
    public function testCappingTheEntryListKeepsTheFeedImage(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, $this->parsedEntries(250), 'https://example.com/logo.png');

        self::assertSame(200, $this->recorder->record($feed, $discovered));

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }
}

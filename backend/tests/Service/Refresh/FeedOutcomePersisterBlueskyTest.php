<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Ingest\PlatformEntryRule\BlueskyEntryRule;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReadsFixtures;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterBlueskyTest extends DbTestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string VIDEO_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27';
    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string TISCH_TEXT = 'Billionaire heiress Jessica Tisch is a holdover from Eric Adams’ mayoral'
        . ' administration who has pushed to expand surveillance infrastructure in New York City. Progressives are'
        . " calling on her to step down if ICE doesn't leave the city.";

    private MockClock $clock;
    private FakeAppView $appView;
    private RecordingSearchIndexWriter $indexWriter;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 17:00:00', 'UTC');
        $this->appView = new FakeAppView();
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('video');
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->feed = new Feed('https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testAFetchedBlueskyFeedStoresItsPostsWithTheirEmbeds(): void
    {
        $result = $this->persistFetched();

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        $tisch = $this->entry(self::TISCH);
        self::assertStringStartsWith(
            'Billionaire heiress Jessica Tisch',
            (string) $tisch->getContentHtml(),
        );
        self::assertStringContainsString(
            'leave the city.<figure class="link-card"><a href="' . self::CARD . '"',
            (string) $tisch->getContentHtml(),
        );
        self::assertSame(self::TISCH_TEXT, $tisch->getSummary());
        $video = $this->entry(self::VIDEO_POST);
        self::assertStringNotContainsString('[contains quote post', (string) $video->getContentHtml());
        self::assertStringContainsString('<figure class="post-video"><video', (string) $video->getContentHtml());
        self::assertSame('video', $video->getMedia()[1]->kind ?? null);
        self::assertSame(0, $this->pendingCount());
        self::assertCount(1, $this->indexWriter->upserts);
        self::assertCount(2, $this->indexWriter->upserts[0]);
        self::assertContains(self::TISCH_TEXT, array_map(
            static fn (IndexedEntryModel $indexed): ?string => $indexed->summary,
            $this->indexWriter->upserts[0],
        ));
    }

    public function testANotModifiedRefreshFillsThePostsTheAppViewMissedBefore(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $this->persistFetched();
        self::assertSame(2, $this->pendingCount());
        self::assertStringEndsWith(self::CARD, (string) $this->entry(self::TISCH)->getContentHtml());
        $this->feed = $this->reloadedFeed();

        $this->appView->recovers();
        $this->clock->sleep(1800);
        $result = $this->persister()->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($this->feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::NotModified, $result->outcome);
        self::assertSame(0, $this->pendingCount());
        self::assertStringContainsString(
            '<figure class="link-card">',
            (string) $this->entry(self::TISCH)->getContentHtml(),
        );
        self::assertCount(2, array_slice($this->indexWriter->upserts, -1)[0]);
    }

    public function testAFailingPassLeavesTheRefreshOutcomeAlone(): void
    {
        $result = $this->persisterWith(PostEnrichers::idle($this->entityManager, $this->clock))->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $this->feed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame(2, $this->pendingCount());
    }

    /** FeedOutcomePersister and PostEnricher may be inlined; building RefreshRunner autowires both. */
    public function testTheContainerBuildsTheRefreshWithTheEnricher(): void
    {
        self::assertInstanceOf(RefreshRunner::class, self::getContainer()->get(RefreshRunner::class));
    }

    private function persistFetched(): FeedRefreshResultModel
    {
        return $this->persister()->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $this->feed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );
    }

    private function persister(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);

        return $this->persisterWith(PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $throttle),
            new NullLogger(),
        ));
    }

    private function persisterWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $this->entityManager,
            $feedRepository,
            $bodyParser,
            EntryIngestors::withPlatformRules(
                $this->entityManager,
                $this->clock,
                new PlatformEntryRules([new BlueskyEntryRule()]),
            ),
            FeedSchedulers::build($this->clock),
            new EntryIndexer($this->indexWriter, new NullLogger()),
            $postEnricher,
            new NullLogger(),
        );
    }

    private function entry(string $guid): Entry
    {
        $this->entityManager->clear();
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid' => $guid]);
        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    /** entry() clears the EntityManager, so a feed passed to persist() again must be managed anew. */
    private function reloadedFeed(): Feed
    {
        $feed = $this->entityManager->find(Feed::class, $this->feed->requireId());
        self::assertInstanceOf(Feed::class, $feed);

        return $feed;
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(PendingPostEnrichment::class)->count([]);
    }
}

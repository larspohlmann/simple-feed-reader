<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\SelfThreadEnrichers;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterBlueskyTest extends DbTestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string TECHPRESSO = 'at://did:plc:t3o4nfbk4lq62necw2n35m4f/app.bsky.feed.post/3my2nlat6px2v';
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
        self::assertStringEndsWith('leave the city.', (string) $tisch->getContentHtml());
        self::assertSame(self::TISCH_TEXT, $tisch->getSummary());
        $video = $this->entry(self::VIDEO_POST);
        self::assertStringNotContainsString('[contains quote post', (string) $video->getContentHtml());
        self::assertStringContainsString('<figure class="post-video"><video', (string) $video->getContentHtml());
        self::assertSame('video', $video->getMedia()[1]->kind ?? null);
        self::assertSame(0, $this->pendingCount());
        self::assertCount(2, $this->indexWriter->upserts);
        [$created, $filled] = $this->indexWriter->upserts;
        self::assertCount(2, $created);
        self::assertCount(2, $filled);
        self::assertContains(self::TISCH_TEXT, array_map(
            static fn (IndexedEntryModel $indexed): ?string => $indexed->summary,
            $filled,
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
        self::assertStringNotContainsString(self::CARD, (string) $this->entry(self::TISCH)->getContentHtml());
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

    public function testAPostWithSelfRepliesIsFoldedIntoItsThreadInTheSameRefresh(): void
    {
        $this->appView->knowsFixture('self-thread');
        $this->appView->knowsThreadFixture('self-thread-replies');
        $feed = new Feed('https://bsky.app/profile/techpresso.bsky.social/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        $this->persisterFolding()->persist($feed, $this->fetchedRss($feed, self::techpressoRss()), $this->clock->now());

        $techpresso = $this->entry(self::TECHPRESSO);
        $thread = SelfThreadEnrichers::threadPosts($this->entityManager)->byEntryIds([$techpresso->requireId()]);
        $posts = $thread[$techpresso->requireId()] ?? [];
        self::assertCount(2, $posts);
        self::assertSame(
            'https://www.macrumors.com/2026/10/16/app-store-rules-deepfakes/',
            $posts[1]->linkCard->content->url ?? null,
        );
        self::assertSame(0, $this->pendingCount());
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

    private function persisterFolding(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $appView = new AppViewClient($this->appView, $throttle);
        $postEnricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            $appView,
            new NullLogger(),
            $this->indexWriter,
        );

        return FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->indexingInto($this->indexWriter)
            ->foldingWith(SelfThreadEnrichers::overBluesky($this->entityManager, $this->clock, $appView))
            ->enrichingWith($postEnricher);
    }

    private function fetchedRss(Feed $feed, string $body): FetchOutcomeModel
    {
        return FetchOutcomeModel::succeeded(FetchResponseModel::fetched($feed->getUrl(), false, $body, null, null));
    }

    private static function techpressoRss(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><description>News</description>'
            . '<link>https://bsky.app/profile/techpresso.bsky.social</link><title>@techpresso.bsky.social</title><item>'
            . '<link>https://bsky.app/profile/techpresso.bsky.social/post/3my2nlat6px2v</link>'
            . '<description>Apple has updated its App Store guidelines to ban apps that produce AI-generated'
            . " nonconsensual intimate imagery, including so-called 'nudify' apps.</description>"
            . '<pubDate>17 Oct 2026 08:15 +0000</pubDate>'
            . '<guid isPermaLink="false">' . self::TECHPRESSO . '</guid></item></channel></rss>';
    }

    private function persister(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);

        return $this->persisterWith(PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $throttle),
            new NullLogger(),
            $this->indexWriter,
        ));
    }

    private function persisterWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        return FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->indexingInto($this->indexWriter)
            ->enrichingWith($postEnricher);
    }

    private function entry(string $guid): Entry
    {
        $this->entityManager->clear();
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => $guid]);
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
        return $this->entityManager->getRepository(ShareEnrichment::class)->count([]);
    }
}

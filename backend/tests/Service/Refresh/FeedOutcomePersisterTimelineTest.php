<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Entity\ThreadPost;
use App\Enum\SourceFormat;
use App\Repository\ThreadPostRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskyTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReadsFixtures;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterTimelineTest extends DbTestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string VIDEO_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27';

    private const string HOME = 'social:bluesky:1:home';
    private const string ALICE = 'did:plc:alice';
    private const string BOB = 'did:plc:bob';
    private const string ROOT = 'at://did:plc:bob/app.bsky.feed.post/3root';
    private const string REPLY_ONE = 'at://did:plc:bob/app.bsky.feed.post/3replyone';
    private const string REPLY_TWO = 'at://did:plc:bob/app.bsky.feed.post/3replytwo';
    private const array POSTED_AT = [
        self::ROOT => '2026-10-10T16:00:00Z',
        self::REPLY_ONE => '2026-10-10T16:01:00Z',
        self::REPLY_TWO => '2026-10-10T16:02:00Z',
    ];

    private MockClock $clock;
    private FakeAppView $appView;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 17:00:00', 'UTC');
        $this->appView = new FakeAppView();
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('video');
    }

    public function testATimelinePostEndsAsTheSameRssPostDoesOnceTheAppViewFilledIt(): void
    {
        $rssFeed = $this->feed('https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/rss', SourceFormat::XML);
        $timelineFeed = $this->feed('social:bluesky:1:home', SourceFormat::BLUESKY_API);
        $persister = $this->persister();
        $persister->persist(
            $rssFeed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $rssFeed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );
        $appViewCalls = \count($this->appView->requests);

        $result = $persister->storeTimeline(
            $timelineFeed,
            TimelinePageModel::of(
                $this->parsedMotherJones($rssFeed)->entries,
                KnownSharesModel::ofBlueskyPosts([
                    self::TISCH => Bluesky::post('external'),
                    self::VIDEO_POST => Bluesky::post('video'),
                ]),
                null,
            ),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertCount($appViewCalls, $this->appView->requests);
        self::assertSame(0, $this->entityManager->getRepository(ShareEnrichment::class)->count());
        foreach ([self::TISCH, self::VIDEO_POST] as $guid) {
            self::assertSame($this->stateOf($rssFeed, $guid), $this->stateOf($timelineFeed, $guid), $guid);
        }
    }

    public function testARootAndItsRepliesOnOnePageBecomeOneThreadedEntry(): void
    {
        $feed = $this->feed(self::HOME, SourceFormat::BLUESKY_API);

        $result = $this->persister()->storeTimeline($feed, $this->wholeThread(), $this->clock->now());

        self::assertSame(1, $result->entriesCreated);
        self::assertSame([self::ROOT], $this->guidsOf($feed));
        self::assertSame(
            [self::webUrl(self::REPLY_ONE) => 1, self::webUrl(self::REPLY_TWO) => 2],
            $this->positionsOf($feed),
        );
    }

    public function testRepliesInALaterRefreshFoldIntoTheStoredRoot(): void
    {
        $feed = $this->feed(self::HOME, SourceFormat::BLUESKY_API);
        $persister = $this->persister();

        $persister->storeTimeline($feed, self::page([self::item(self::ROOT)]), $this->clock->now());
        $persister->storeTimeline($feed, self::page([
            self::item(self::REPLY_ONE, self::ROOT, self::ROOT),
            self::item(self::ROOT),
        ]), $this->clock->now());
        $persister->storeTimeline($feed, $this->wholeThread(), $this->clock->now());

        self::assertSame([self::ROOT], $this->guidsOf($feed));
        self::assertSame(
            [self::webUrl(self::REPLY_ONE) => 1, self::webUrl(self::REPLY_TWO) => 2],
            $this->positionsOf($feed),
        );
        self::assertSame(2, $this->entityManager->getRepository(ThreadPost::class)->count());
    }

    public function testASelfReplyWhoseRootIsNotStoredStaysItsOwnEntry(): void
    {
        $feed = $this->feed(self::HOME, SourceFormat::BLUESKY_API);

        $result = $this->persister()->storeTimeline(
            $feed,
            self::page([self::item(self::REPLY_ONE, self::ROOT, self::ROOT)]),
            $this->clock->now(),
        );

        self::assertSame(1, $result->entriesCreated);
        self::assertSame([self::REPLY_ONE], $this->guidsOf($feed));
        self::assertSame(0, $this->entityManager->getRepository(ThreadPost::class)->count());
    }

    public function testASelfReplyAlreadyStoredAsAnEntryIsLeftAlone(): void
    {
        $feed = $this->feed(self::HOME, SourceFormat::BLUESKY_API);
        $persister = $this->persister();
        $plainReply = self::page([self::item(self::REPLY_ONE)])->parsed->entries;
        $persister->storeTimeline(
            $feed,
            TimelinePageModel::of($plainReply, KnownSharesModel::none(), null),
            $this->clock->now(),
        );

        $persister->storeTimeline($feed, $this->wholeThread(), $this->clock->now());

        self::assertSame([self::REPLY_ONE, self::REPLY_TWO, self::ROOT], $this->guidsOf($feed));
        self::assertSame(0, $this->entityManager->getRepository(ThreadPost::class)->count());
    }

    public function testAStoredFailureBacksTheFeedOffAndKeepsTheMessage(): void
    {
        $feed = $this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API);

        $result = $this->persister()->storeFailure($feed, new \RuntimeException('The Mastodon instance answered 502.'));

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame('The Mastodon instance answered 502.', $feed->getLastErrorMessage());
        self::assertSame(1, $feed->getConsecutiveFailures());
    }

    public function testAStoredThrottleOnlyMovesTheNextFetch(): void
    {
        $feed = $this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API);

        $result = $this->persister()->storeThrottled($feed, 600);

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame('2026-10-10 17:10:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    private function persister(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);

        return FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->enrichingWith(PostEnrichers::build(
                $this->entityManager,
                $this->clock,
                new AppViewClient($this->appView, $throttle),
                new NullLogger(),
            ));
    }

    private function wholeThread(): TimelinePageModel
    {
        return self::page([
            self::item(self::REPLY_TWO, self::ROOT, self::REPLY_ONE),
            self::item(self::REPLY_ONE, self::ROOT, self::ROOT),
            self::item(self::ROOT),
        ]);
    }

    /** @param list<JsonNodeModel> $items */
    private static function page(array $items): TimelinePageModel
    {
        return BlueskyTimelinePage::of($items, self::ALICE);
    }

    private static function item(string $uri, ?string $replyRoot = null, ?string $replyParent = null): JsonNodeModel
    {
        $author = ['did' => self::BOB, 'viewer' => ['following' => 'at://did:plc:alice/app.bsky.graph.follow/1']];
        $reply = $replyRoot === null || $replyParent === null ? [] : ['reply' => [
            'root' => ['uri' => $replyRoot, 'author' => $author],
            'parent' => ['uri' => $replyParent, 'author' => $author],
        ]];

        return JsonNodeModel::of($reply + [
            'post' => [
                'uri' => $uri,
                'author' => ['did' => self::BOB, 'handle' => 'bob.bsky.social', 'displayName' => 'Bob'],
                'record' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => 'Post ' . $uri,
                    'createdAt' => self::POSTED_AT[$uri],
                ],
                'indexedAt' => self::POSTED_AT[$uri],
            ],
        ]);
    }

    private static function webUrl(string $uri): string
    {
        return (string) AtPostUri::webUrl($uri);
    }

    /** @return list<string> */
    private function guidsOf(Feed $feed): array
    {
        $guids = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feed]),
        );
        sort($guids);

        return $guids;
    }

    /** @return array<string, int> */
    private function positionsOf(Feed $feed): array
    {
        $root = $this->entityManager->getRepository(Entry::class)
            ->findOneBy(['feed' => $feed, 'guid.value' => self::ROOT]);
        self::assertInstanceOf(Entry::class, $root);
        $threadPosts = self::getContainer()->get(ThreadPostRepository::class);
        self::assertInstanceOf(ThreadPostRepository::class, $threadPosts);
        $positions = $threadPosts->positionsByEntryIds([$root->requireId()])[$root->requireId()] ?? [];
        asort($positions);

        return $positions;
    }

    private function parsedMotherJones(Feed $rssFeed): ParsedFeedModel
    {
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return $bodyParser->parse($rssFeed, $this->fixture('Bluesky/motherjones.rss'));
    }

    private function feed(string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /** @return array<string, mixed> */
    private function stateOf(Feed $feed, string $guid): array
    {
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed, 'guid.value' => $guid]);
        self::assertInstanceOf(Entry::class, $entry);
        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return [
            'content' => $entry->getContentHtml(),
            'summary' => $entry->getSummary(),
            'shareKind' => $entry->getShareKind()?->value,
            'imageUrl' => $entry->getImageUrl(),
            'media' => serialize($entry->getMedia()),
            'cards' => serialize($cards[$entry->requireId()] ?? []),
        ];
    }
}

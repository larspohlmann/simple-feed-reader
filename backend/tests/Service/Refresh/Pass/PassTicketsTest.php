<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\SocialAccountSlotModel;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class PassTicketsTest extends TestCase
{
    use AssignsEntityIds;

    private const string HOME = 'https://mastodon.example/api/v1/timelines/home?limit=40';

    private RefreshPass $pass;

    public function testAnRssFeedBecomesAFeedTicketOnItsHostCarryingItsValidators(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $rss->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        $tickets = iterator_to_array($this->passTickets([$rss], [])->tickets());

        self::assertSame(ResponseExchangeKind::Feed, $tickets[1]->exchange);
        self::assertSame('https://news.example/feed.xml', $tickets[1]->request->url);
        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
            $tickets[1]->request->headers,
        );
        self::assertEquals([new HostSlotModel(2)], $tickets[1]->slots);
    }

    public function testAPrivateFeedBecomesItsTimelineTicketOnItsAccountAndServer(): void
    {
        $timeline = self::privateFeed(self::account(), 2);

        $tickets = iterator_to_array($this->passTickets([$timeline->getFeed()], [$timeline])->tickets());

        self::assertSame(ResponseExchangeKind::Api, $tickets[2]->exchange);
        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(self::HOME, 4)],
            $tickets[2]->slots,
        );
    }

    public function testFeedsAreTicketedInTheOrderThePassStartsThemWhateverTheirKind(): void
    {
        $account = self::account();
        $home = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $list = self::privateFeed($account, 3, SocialFeedKind::UserList, '42');

        $tickets = $this->passTickets([$home->getFeed(), $rss, $list->getFeed()], [$home, $list])->tickets();

        self::assertSame([1, 2, 3], array_keys(iterator_to_array($tickets)));
    }

    public function testAFeedCountsAsStartedOnlyWhenTheEngineWantsItsTicket(): void
    {
        $one = self::withId(new Feed('https://news.example/one.xml'), 1);
        $two = self::withId(new Feed('https://news.example/two.xml'), 2);

        $this->passTickets([$one, $two], [])->tickets()->current();

        self::assertSame([1], $this->pass->startedFeedIds());
    }

    public function testAnOrphanSettlesWithoutATicketAndTheNextFeedStillStarts(): void
    {
        $orphan = self::privateFeed(self::account(), 1)->getFeed();
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$orphan, $rss], []);

        $tickets = iterator_to_array($passTickets->tickets());
        $unasked = iterator_to_array($passTickets->drainUnasked());

        self::assertSame([2], array_keys($tickets));
        self::assertSame([1], array_keys($unasked));
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[1]);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[1]->failure);
        self::assertSame([1, 2], $this->pass->startedFeedIds());
    }

    public function testAnOrphanIsDrainedOnlyOnce(): void
    {
        $orphan = self::privateFeed(self::account(), 1)->getFeed();
        $passTickets = $this->passTickets([$orphan], []);
        iterator_to_array($passTickets->tickets());

        self::assertSame([1], array_keys(iterator_to_array($passTickets->drainUnasked())));
        self::assertSame([], iterator_to_array($passTickets->drainUnasked()));
    }

    public function testAPausedAccountsFeedSettlesWithoutATicket(): void
    {
        $paused = self::account();
        $paused->markNeedsReconnect();
        $timeline = self::privateFeed($paused, 1);
        $passTickets = $this->passTickets([$timeline->getFeed()], [$timeline]);

        self::assertSame([], iterator_to_array($passTickets->tickets()));
        self::assertInstanceOf(AccountPausedTimelineModel::class, iterator_to_array($passTickets->drainUnasked())[1]);
    }

    public function testTheGateAdmitsEveryRssFeedAndAsksTheRoundAboutAPrivateOne(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());

        $account->markNeedsReconnect();

        self::assertTrue($passTickets->admits(2));
        self::assertFalse($passTickets->admits(1));
    }

    public function testAnRssOutcomeIsPassedThroughAndAPrivateOneIsSettledByItsRound(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());
        $account->markNeedsReconnect();
        $rssOutcome = FetchOutcomeModel::withheld();

        self::assertSame($rssOutcome, $passTickets->settle(2, $rssOutcome));
        self::assertInstanceOf(
            AccountPausedTimelineModel::class,
            $passTickets->settle(1, FetchOutcomeModel::withheld()),
        );
    }

    /**
     * @param list<Feed>       $feeds       in due order
     * @param list<SocialFeed> $socialFeeds the private feeds' rows
     */
    private function passTickets(array $feeds, array $socialFeeds): PassTickets
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $this->pass = new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);
        $socialFeedsByFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $socialFeedsByFeedId[$socialFeed->getFeed()->requireId()] = $socialFeed;
        }
        $round = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter())->start($socialFeedsByFeedId);

        return new PassTickets(new PassFeeds($this->pass, $socialFeedsByFeedId), $round, 2);
    }

    private static function account(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
    }

    private static function privateFeed(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return new SocialFeed($account, $feed, $kind, $remoteId);
    }
}

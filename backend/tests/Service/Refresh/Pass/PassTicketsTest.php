<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Service\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\PrivateFeeds;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class PassTicketsTest extends TestCase
{
    use AssignsEntityIds;

    private RefreshPass $pass;

    public function testFeedsAreTicketedInTheOrderThePassStartsThemWhateverTheirLane(): void
    {
        $account = PrivateFeeds::mastodonAccount();
        $home = PrivateFeeds::mastodon($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $list = PrivateFeeds::mastodon($account, 3, SocialFeedKind::UserList, '42');

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
        $orphan = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), 1)->getFeed();
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$orphan, $rss], []);

        $tickets = iterator_to_array($passTickets->tickets());
        $unasked = iterator_to_array($passTickets->drainUnasked());

        self::assertSame([2], array_keys($tickets));
        self::assertSame([1], array_keys($unasked));
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[1]);
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[1]->outcome);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[1]->outcome->failure);
        self::assertSame([1, 2], $this->pass->startedFeedIds());
    }

    public function testAnOrphanIsDrainedOnlyOnce(): void
    {
        $orphan = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), 1)->getFeed();
        $passTickets = $this->passTickets([$orphan], []);
        iterator_to_array($passTickets->tickets());

        self::assertSame([1], array_keys(iterator_to_array($passTickets->drainUnasked())));
        self::assertSame([], iterator_to_array($passTickets->drainUnasked()));
    }

    public function testAPausedAccountsFeedSettlesWithoutATicket(): void
    {
        $paused = PrivateFeeds::mastodonAccount();
        $paused->markNeedsReconnect();
        $timeline = PrivateFeeds::mastodon($paused, 1);
        $passTickets = $this->passTickets([$timeline->getFeed()], [$timeline]);

        self::assertSame([], iterator_to_array($passTickets->tickets()));
        $unasked = iterator_to_array($passTickets->drainUnasked());
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[1]);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $unasked[1]->outcome);
    }

    public function testTheGateAsksEachFeedsOwnLane(): void
    {
        $account = PrivateFeeds::mastodonAccount();
        $timeline = PrivateFeeds::mastodon($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());

        $account->markNeedsReconnect();

        self::assertTrue($passTickets->admits(2));
        self::assertFalse($passTickets->admits(1));
    }

    public function testEachOutcomeIsSettledByItsFeedsLane(): void
    {
        $account = PrivateFeeds::mastodonAccount();
        $timeline = PrivateFeeds::mastodon($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());
        $account->markNeedsReconnect();
        $rssOutcome = FetchOutcomeModel::withheld();

        $fetched = $passTickets->settle(2, $rssOutcome)->settledOutcome();
        $held = $passTickets->settle(1, FetchOutcomeModel::withheld())->settledOutcome();

        self::assertInstanceOf(FeedPassOutcomeModel::class, $fetched);
        self::assertSame($rssOutcome, $fetched->outcome);
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $held);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $held->outcome);
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

        return new PassTickets(new PassFeeds($this->pass, new FeedLane(2), new TimelineLane($round)));
    }
}

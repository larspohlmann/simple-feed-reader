<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass\PassLane;

use App\Entity\SocialFeed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\SocialAccountSlotModel;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\PrivateFeeds;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineLaneTest extends TestCase
{
    public function testAPrivateFeedsTicketComesFromItsRound(): void
    {
        $timeline = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), 2);

        $tickets = iterator_to_array(self::lane([2 => $timeline])->ticketsFor($timeline->getFeed()));

        self::assertSame([2], array_keys($tickets));
        self::assertSame(ResponseExchangeKind::Api, $tickets[2]->exchange);
        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(4)],
            $tickets[2]->slots,
        );
    }

    public function testTheGateAsksTheRound(): void
    {
        $account = PrivateFeeds::mastodonAccount();
        $timeline = PrivateFeeds::mastodon($account, 2);
        $lane = self::lane([2 => $timeline]);
        iterator_to_array($lane->ticketsFor($timeline->getFeed()));
        self::assertTrue($lane->admits(2));

        $account->markNeedsReconnect();

        self::assertFalse($lane->admits(2));
    }

    public function testASettledOutcomeIsStoredAsItsTimelineOutcome(): void
    {
        $account = PrivateFeeds::mastodonAccount();
        $timeline = PrivateFeeds::mastodon($account, 2);
        $lane = self::lane([2 => $timeline]);
        iterator_to_array($lane->ticketsFor($timeline->getFeed()));
        $account->markNeedsReconnect();

        $passOutcome = $lane->settle(2, FetchOutcomeModel::withheld())->settledOutcome();

        self::assertInstanceOf(TimelinePassOutcomeModel::class, $passOutcome);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $passOutcome->outcome);
    }

    public function testAnOrphanIsDrainedAsItsTimelineOutcome(): void
    {
        $orphan = PrivateFeeds::mastodon(PrivateFeeds::mastodonAccount(), 2)->getFeed();
        $lane = self::lane([]);

        self::assertSame([], iterator_to_array($lane->ticketsFor($orphan)));
        $unasked = iterator_to_array($lane->drainUnasked());

        self::assertSame([2], array_keys($unasked));
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[2]);
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[2]->outcome);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[2]->outcome->failure);
    }

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    private static function lane(array $socialFeedsByFeedId): TimelineLane
    {
        $rounds = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter());

        return new TimelineLane($rounds->start($socialFeedsByFeedId));
    }
}

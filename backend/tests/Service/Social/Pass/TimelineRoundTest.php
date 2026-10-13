<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineRound;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundTest extends TestCase
{
    public function testAFeedWithoutItsSocialFeedSettlesAsOrphanedWithoutATicket(): void
    {
        $round = self::round();

        self::assertSame([], iterator_to_array($round->ticketFor(999)));
        $unasked = iterator_to_array($round->drainUnasked());

        self::assertSame([999], array_keys($unasked));
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[999]);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[999]->failure);
        self::assertSame([], iterator_to_array($round->drainUnasked()));
    }

    public function testTheGateCannotAskAboutAFeedWithoutItsSocialFeed(): void
    {
        $this->expectException(PrivateFeedOrphanedException::class);

        self::round()->admits(999);
    }

    private static function round(): TimelineRound
    {
        $sources = SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter());

        return new TimelineRound($sources, new TimelineSlotsModel(3, 4), []);
    }
}

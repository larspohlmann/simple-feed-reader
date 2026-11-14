<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class FeedLaneTest extends TestCase
{
    use AssignsEntityIds;

    public function testAFeedIsOneTicketOnItsHostCarryingItsValidators(): void
    {
        $feed = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $feed->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');
        $lane = new FeedLane(2);

        $tickets = iterator_to_array($lane->ticketsFor($feed));

        self::assertSame([1], array_keys($tickets));
        self::assertSame(ResponseExchangeKind::Feed, $tickets[1]->exchange);
        self::assertSame('https://news.example/feed.xml', $tickets[1]->request->url);
        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
            $tickets[1]->request->headers,
        );
        self::assertEquals([new HostSlotModel(2)], $tickets[1]->slots);
    }

    public function testEveryFeedIsAdmitted(): void
    {
        $lane = new FeedLane(2);

        self::assertTrue($lane->admits(1));
    }

    public function testARawOutcomeIsStoredAsFetched(): void
    {
        $outcome = FetchOutcomeModel::failed(new FeedUnreachableException('https://news.example/feed.xml: timed out'));
        $lane = new FeedLane(2);

        $passOutcome = $lane->settle(1, $outcome)->settledOutcome();

        self::assertInstanceOf(FeedPassOutcomeModel::class, $passOutcome);
        self::assertSame($outcome, $passOutcome->outcome);
    }

    public function testNothingSettlesWithoutARequest(): void
    {
        $lane = new FeedLane(2);

        self::assertSame([], iterator_to_array($lane->drainUnasked()));
    }
}

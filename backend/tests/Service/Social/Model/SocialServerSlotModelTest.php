<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\RequestSlots;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class SocialServerSlotModelTest extends TestCase
{
    private const string HOME = 'https://mastodon.example/api/v1/timelines/home';

    public function testItsKeyIsTheRequestsHost(): void
    {
        $slot = new SocialServerSlotModel(4);

        self::assertSame('mastodon.example', $slot->keyFor('https://www.Mastodon.example:443/api/v1/timelines/home'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new SocialServerSlotModel(4);

        self::assertSame(4, $slot->capacity());
    }

    public function testAnRssHostSlotAndASocialServerSlotOnOneHostNeverShareACap(): void
    {
        $slots = new RequestSlots();
        $slots->acquire(self::timelineAttempt());

        $rss = FetchAttemptModel::start(2, FeedTickets::cappedAt('https://mastodon.example/@bob.rss', 1));

        self::assertFalse($slots->hasCapacityFor(self::timelineAttempt()), 'the server slot is full');
        self::assertTrue($slots->hasCapacityFor($rss), 'the RSS host slot on the same host is not');
    }

    private static function timelineAttempt(): FetchAttemptModel
    {
        return FetchAttemptModel::start(
            1,
            RequestTicketModel::api(ApiRequestModel::get(self::HOME), [new SocialServerSlotModel(1)]),
        );
    }
}

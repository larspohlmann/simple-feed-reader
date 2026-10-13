<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Model\SocialAccountSlotModel;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Service\Social\TimelineRounds;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\PrivateFeeds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundsTest extends TestCase
{
    use AssignsEntityIds;

    public function testATimelineTicketHoldsItsAccountsSlotAndItsServersSlot(): void
    {
        $rounds = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter());
        $account = self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
        $socialFeed = PrivateFeeds::mastodon($account, 1);

        $tickets = iterator_to_array($rounds->start([1 => $socialFeed])->ticketFor(1));

        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(4)],
            $tickets[1]->slots,
        );
    }

    public function testRejectsAnAccountConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-account concurrency must be at least 1, got 0.');

        new TimelineRounds(SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter()), 0, 4);
    }

    public function testRejectsAServerConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-server concurrency must be at least 1, got 0.');

        new TimelineRounds(SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter()), 3, 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\TimelineRounds;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineRoundsTest extends TestCase
{
    use AssignsEntityIds;

    private const string HOME = 'https://mastodon.example/api/v1/timelines/home?limit=40';

    public function testATimelineTicketHoldsItsAccountsSlotAndItsServersSlot(): void
    {
        $rounds = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter());
        $account = self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
        $socialFeed = new SocialFeed(
            $account,
            self::withId(new Feed('social:mastodon:7:home'), 1),
            SocialFeedKind::Home,
            '',
        );

        $tickets = iterator_to_array($rounds->start()->tickets([1 => $socialFeed]));

        self::assertSame(
            ['slot:social-account:7' => 3, 'slot:social-host:mastodon.example' => 4],
            self::capacitiesByKey($tickets[1]),
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

    /** @return array<string, int> */
    private static function capacitiesByKey(RequestTicketModel $ticket): array
    {
        $capacities = [];
        foreach ($ticket->slots as $slot) {
            $capacities[$slot->keyFor(self::HOME)] = $slot->capacity;
        }

        return $capacities;
    }
}

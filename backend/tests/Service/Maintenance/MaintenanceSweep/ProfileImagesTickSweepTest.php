<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\HostThrottle;
use App\Service\Maintenance\MaintenanceSweep\ProfileImagesTickSweep;
use App\Service\Social\ProfileImageSweep;
use App\Service\Social\ProfileVisibilityCache;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ProfileImagesTickSweepTest extends DbTestCase
{
    public function testAsksForThePicturesWhenOneCallFitsBeforeTheDeadline(): void
    {
        self::assertSame(['checked' => 1, 'changed' => 0], $this->outcomeWithinSecondsOfTheDeadline(20));
    }

    public function testLeavesThePicturesForLaterWhenLessThanOneCallIsLeft(): void
    {
        self::assertSame(['checked' => 0, 'changed' => 0], $this->outcomeWithinSecondsOfTheDeadline(19));
    }

    /** @return array<string, int|bool> */
    private function outcomeWithinSecondsOfTheDeadline(int $seconds): array
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-sweep-profile-pictures@example.com', $now);
        $this->entityManager->persist($subscriber);
        $profileFeed = new Feed('https://bsky.app/profile/tick.bsky.social/rss');
        $profileFeed->scheduleNextFetchAt($now->modify('+1 hour'));
        $this->entityManager->persist($profileFeed);
        $this->entityManager->persist(new Subscription($subscriber, $profileFeed, $now));
        $this->entityManager->flush();
        $appView = new StubFeedFetcher();
        $appView->willReturnBody(
            'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles?actors=tick.bsky.social',
            '{"profiles":[]}',
        );
        $feeds = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        $sweep = new ProfileImagesTickSweep(
            new ProfileImageSweep(
                $feeds,
                new AppViewClient($appView, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
                $this->entityManager,
                new NaiveUtcClock($clock),
                new NullLogger(),
                new ProfileVisibilityCache(new ArrayAdapter()),
            ),
            $clock,
        );

        return $sweep->run($now->modify(\sprintf('+%d seconds', $seconds)))->outcome;
    }
}

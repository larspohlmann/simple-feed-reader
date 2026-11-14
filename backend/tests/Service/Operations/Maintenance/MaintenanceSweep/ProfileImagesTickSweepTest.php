<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Social\Bluesky\AppViewClient;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Operations\Maintenance\MaintenanceSweep\ProfileImagesTickSweep;
use App\Service\Social\Social\ProfileImageSweep;
use App\Service\Social\Social\ProfileVisibilityCache;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ProfileImagesTickSweepTest extends DbTestCase
{
    public function testAsksForThePicturesWhenOneCallFitsBeforeTheDeadline(): void
    {
        self::assertEquals(new ProfileImageSweepReportModel(1, 0), $this->reportWithinSecondsOfTheDeadline(20));
    }

    public function testLeavesThePicturesForLaterWhenLessThanOneCallIsLeft(): void
    {
        self::assertEquals(ProfileImageSweepReportModel::none(), $this->reportWithinSecondsOfTheDeadline(19));
    }

    private function reportWithinSecondsOfTheDeadline(int $seconds): ProfileImageSweepReportModel
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

        return $sweep->run($now->modify(\sprintf('+%d seconds', $seconds)));
    }
}

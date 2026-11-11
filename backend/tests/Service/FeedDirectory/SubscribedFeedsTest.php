<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\FeedDirectory\SubscribedFeeds;
use App\Tests\DbTestCase;

final class SubscribedFeedsTest extends DbTestCase
{
    public function testItListsNormalisedUrlsAndHostsSortedAndWithoutRepeats(): void
    {
        $user = new User('directory-subscribed@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->entityManager->persist($user);
        foreach (
            [
                'https://www.Zebra.test/feed/',
                'https://zebra.test/feed',
                'https://beta.test/rss',
                'https://alpha.test/atom',
            ] as $url
        ) {
            $this->subscribe($user, $url);
        }
        $this->entityManager->persist(new Feed('https://stranger.test/feed'));
        $this->entityManager->flush();

        $subscribed = $this->subscribedFeeds()->of($user->requireId());

        self::assertSame(['alpha.test/atom', 'beta.test/rss', 'zebra.test/feed'], $subscribed->urls);
        self::assertSame(['alpha.test', 'beta.test', 'zebra.test'], $subscribed->hosts);
    }

    public function testAnAccountWithoutSubscriptionsHasNoFeeds(): void
    {
        $user = new User('directory-none@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $subscribed = $this->subscribedFeeds()->of($user->requireId());

        self::assertSame([], $subscribed->urls);
        self::assertSame([], $subscribed->hosts);
    }

    private function subscribe(User $user, string $url): void
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01 00:00:00')));
    }

    private function subscribedFeeds(): SubscribedFeeds
    {
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return new SubscribedFeeds($feeds);
    }
}

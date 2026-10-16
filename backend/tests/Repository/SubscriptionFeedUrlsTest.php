<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionFeedUrls;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class SubscriptionFeedUrlsTest extends DbTestCase
{
    use SeedsUsers;

    public function testReadsTheOwnersSubscriptionsWithTheirFeedsInCreationOrder(): void
    {
        $owner = $this->user('feed-urls-owner@example.com');
        $later = $this->subscribe($owner, 'https://example.com/later.xml', '2026-07-02T00:00:00Z');
        $titled = $this->subscribe($owner, 'https://example.com/titled.xml', '2026-07-01T00:00:00Z');
        $titled->getFeed()->setTitle('Feed title');
        $titled->getFeed()->setImageUrl('https://cdn.test/titled.png');
        $titled->setCustomTitle('My title');
        $this->subscribe(
            $this->user('feed-urls-other@example.com'),
            'https://example.com/foreign.xml',
            '2026-07-01T00:00:00Z',
        );
        $this->entityManager->flush();

        self::assertSame(
            [
                [
                    'id' => $titled->requireId(),
                    'customTitle' => 'My title',
                    'feedUrl' => 'https://example.com/titled.xml',
                    'feedTitle' => 'Feed title',
                    'feedImageUrl' => 'https://cdn.test/titled.png',
                ],
                [
                    'id' => $later->requireId(),
                    'customTitle' => null,
                    'feedUrl' => 'https://example.com/later.xml',
                    'feedTitle' => null,
                    'feedImageUrl' => null,
                ],
            ],
            $this->feedUrls()->forUser($owner->requireId()),
        );
    }

    private function feedUrls(): SubscriptionFeedUrls
    {
        $feedUrls = self::getContainer()->get(SubscriptionFeedUrls::class);
        self::assertInstanceOf(SubscriptionFeedUrls::class, $feedUrls);

        return $feedUrls;
    }

    private function subscribe(User $user, string $feedUrl, string $createdAt): Subscription
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}

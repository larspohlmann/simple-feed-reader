<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionRepositoryTest extends DbTestCase
{
    public function testGetOneForUserReturnsTheOwnersSubscription(): void
    {
        $owner = $this->userFactory()->create('subscription-owner@example.com');
        $subscription = $this->subscription($owner);

        self::assertSame(
            $subscription,
            $this->repository()->getOneForUser($owner->requireId(), $subscription->requireId()),
        );
    }

    public function testGetOneForUserRefusesAnotherUsersSubscription(): void
    {
        $subscription = $this->subscription($this->userFactory()->create('subscription-owner@example.com'));
        $stranger = $this->userFactory()->create('subscription-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->repository()->getOneForUser($stranger->requireId(), $subscription->requireId());
    }

    public function testFindIncludedInAllItemsForUserSkipsHiddenFeedsAndOtherUsers(): void
    {
        $owner = $this->userFactory()->create('all-items-owner@example.com');
        $included = $this->subscriptionToFeed($owner, 'https://example.com/included.xml');
        $hidden = $this->subscriptionToFeed($owner, 'https://example.com/hidden.xml');
        $hidden->setIncludeInAllItems(false);
        $alsoIncluded = $this->subscriptionToFeed($owner, 'https://example.com/also-included.xml');
        $this->subscriptionToFeed(
            $this->userFactory()->create('all-items-other@example.com'),
            'https://example.com/foreign.xml',
        );
        $this->entityManager->flush();

        self::assertSame(
            [$included->requireId(), $alsoIncluded->requireId()],
            array_map(
                static fn (Subscription $subscription): int => $subscription->requireId(),
                $this->repository()->findIncludedInAllItemsForUser($owner->requireId()),
            ),
        );
    }

    public function testFindFeedUrlsForUserReadsTheOwnersSubscriptionsInCreationOrder(): void
    {
        $owner = $this->userFactory()->create('feed-urls-owner@example.com');
        $titled = $this->subscriptionToFeed($owner, 'https://example.com/titled.xml');
        $titled->getFeed()->setTitle('Feed title');
        $titled->setCustomTitle('My title');
        $plain = $this->subscriptionToFeed($owner, 'https://example.com/plain.xml');
        $this->subscriptionToFeed(
            $this->userFactory()->create('feed-urls-other@example.com'),
            'https://example.com/foreign.xml',
        );
        $this->entityManager->flush();

        self::assertSame(
            [
                [
                    'id' => $titled->requireId(),
                    'customTitle' => 'My title',
                    'feedUrl' => 'https://example.com/titled.xml',
                    'feedTitle' => 'Feed title',
                ],
                [
                    'id' => $plain->requireId(),
                    'customTitle' => null,
                    'feedUrl' => 'https://example.com/plain.xml',
                    'feedTitle' => null,
                ],
            ],
            $this->repository()->findFeedUrlsForUser($owner->requireId()),
        );
    }

    private function repository(): SubscriptionRepository
    {
        $repository = $this->entityManager->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);

        return $repository;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->entityManager, $hasher);
    }

    private function subscription(User $owner): Subscription
    {
        return $this->subscriptionToFeed($owner, 'https://example.com/owned-lookup.xml');
    }

    private function subscriptionToFeed(User $owner, string $feedUrl): Subscription
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}

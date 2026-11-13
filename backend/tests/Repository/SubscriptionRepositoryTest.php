<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionScopeRepository;
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
        $hidden->changeInclusions(new InclusionChange(allItems: false));
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
                $this->scopeRepository()->findIncludedInAllItemsForUser($owner->requireId()),
            ),
        );
    }

    public function testFindFeedUrlsForUserReadsTheOwnersFeedsInCreationOrder(): void
    {
        $owner = $this->userFactory()->create('feed-urls-owner@example.com');
        $later = $this->subscriptionToFeed($owner, 'https://example.com/later.xml', '2026-07-02T00:00:00Z');
        $titled = $this->subscriptionToFeed($owner, 'https://example.com/titled.xml');
        $titled->getFeed()->setTitle('Feed title');
        $titled->getFeed()->setImageUrl('https://cdn.test/titled.png');
        $titled->setCustomTitle('My title');
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
            $this->repository()->findFeedSummariesForUser($owner->requireId()),
        );
    }

    private function scopeRepository(): SubscriptionScopeRepository
    {
        $repository = self::getContainer()->get(SubscriptionScopeRepository::class);
        self::assertInstanceOf(SubscriptionScopeRepository::class, $repository);

        return $repository;
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

    private function subscriptionToFeed(
        User $owner,
        string $feedUrl,
        string $createdAt = '2026-07-01T00:00:00Z',
    ): Subscription {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}

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
    public function testGetOneOwnedByReturnsTheOwnersSubscription(): void
    {
        $owner = $this->userFactory()->create('subscription-owner@example.com');
        $subscription = $this->subscription($owner);

        self::assertSame(
            $subscription,
            $this->repo()->getOneOwnedBy($subscription->requireId(), $owner->requireId()),
        );
    }

    public function testGetOneOwnedByRefusesAnotherUsersSubscription(): void
    {
        $subscription = $this->subscription($this->userFactory()->create('subscription-owner@example.com'));
        $stranger = $this->userFactory()->create('subscription-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->repo()->getOneOwnedBy($subscription->requireId(), $stranger->requireId());
    }

    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->em, $hasher);
    }

    private function subscription(User $owner): Subscription
    {
        $feed = new Feed('https://example.com/owned-lookup.xml');
        $this->em->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}

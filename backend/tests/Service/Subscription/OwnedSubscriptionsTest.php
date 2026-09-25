<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Subscription\OwnedSubscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Exception\InvalidSelectionException;

final class OwnedSubscriptionsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnedSubscriptions $owned;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owned = self::getContainer()->get(OwnedSubscriptions::class);
        self::assertInstanceOf(OwnedSubscriptions::class, $owned);
        $this->owned = $owned;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);

        return $subscription;
    }

    public function testResolvesOwnedIdsKeyedById(): void
    {
        $user = $this->user('owner-resolves@example.com');
        $first = $this->subscription($user, 'https://first.example/feed.xml');
        $second = $this->subscription($user, 'https://second.example/feed.xml');
        $this->em->flush();

        $resolved = $this->owned->resolve(
            [$second->requireId(), $first->requireId()],
            $user->requireId(),
        );

        self::assertCount(2, $resolved);
        self::assertSame($first, $resolved[$first->requireId()]);
        self::assertSame($second, $resolved[$second->requireId()]);
    }

    public function testRejectsAnIdThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('owner-mine@example.com');
        $theirs = $this->user('owner-theirs@example.com');
        $ours = $this->subscription($mine, 'https://ours.example/feed.xml');
        $foreign = $this->subscription($theirs, 'https://foreign.example/feed.xml');
        $this->em->flush();

        $this->expectException(InvalidSelectionException::class);
        $this->owned->resolve(
            [$ours->requireId(), $foreign->requireId()],
            $mine->requireId(),
        );
    }

    public function testRejectsAnIdThatDoesNotExist(): void
    {
        $user = $this->user('owner-missing@example.com');
        $this->em->flush();

        $this->expectException(InvalidSelectionException::class);
        $this->owned->resolve([999_999], $user->requireId());
    }

    public function testRejectsADuplicateId(): void
    {
        $user = $this->user('owner-duplicate@example.com');
        $subscription = $this->subscription($user, 'https://dupe.example/feed.xml');
        $this->em->flush();

        $id = $subscription->requireId();

        $this->expectException(InvalidSelectionException::class);
        $this->owned->resolve([$id, $id], $user->requireId());
    }

    /**
     * Same ownership guarantee as resolve() — the eager variant must reject
     * and key results identically, differing only in what it eager-loads.
     */
    public function testResolveWithAssociationsResolvesOwnedIdsKeyedById(): void
    {
        $user = $this->user('owner-assoc-resolves@example.com');
        $first = $this->subscription($user, 'https://assoc-first.example/feed.xml');
        $second = $this->subscription($user, 'https://assoc-second.example/feed.xml');
        $this->em->flush();

        $resolved = $this->owned->resolveWithAssociations(
            [$second->requireId(), $first->requireId()],
            $user->requireId(),
        );

        self::assertCount(2, $resolved);
        self::assertSame($first, $resolved[$first->requireId()]);
        self::assertSame($second, $resolved[$second->requireId()]);
    }

    public function testResolveWithAssociationsRejectsAnIdThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('owner-assoc-mine@example.com');
        $theirs = $this->user('owner-assoc-theirs@example.com');
        $ours = $this->subscription($mine, 'https://assoc-ours.example/feed.xml');
        $foreign = $this->subscription($theirs, 'https://assoc-foreign.example/feed.xml');
        $this->em->flush();

        $this->expectException(InvalidSelectionException::class);
        $this->owned->resolveWithAssociations(
            [$ours->requireId(), $foreign->requireId()],
            $mine->requireId(),
        );
    }
}

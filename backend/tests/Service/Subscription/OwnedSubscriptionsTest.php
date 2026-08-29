<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Subscription\OwnedSubscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
            [(int) $second->getId(), (int) $first->getId()],
            (int) $user->getId(),
        );

        self::assertCount(2, $resolved);
        self::assertSame($first, $resolved[(int) $first->getId()]);
        self::assertSame($second, $resolved[(int) $second->getId()]);
    }

    public function testRejectsAnIdThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('owner-mine@example.com');
        $theirs = $this->user('owner-theirs@example.com');
        $ours = $this->subscription($mine, 'https://ours.example/feed.xml');
        $foreign = $this->subscription($theirs, 'https://foreign.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve(
            [(int) $ours->getId(), (int) $foreign->getId()],
            (int) $mine->getId(),
        );
    }

    public function testRejectsAnIdThatDoesNotExist(): void
    {
        $user = $this->user('owner-missing@example.com');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve([999_999], (int) $user->getId());
    }

    public function testRejectsADuplicateId(): void
    {
        $user = $this->user('owner-duplicate@example.com');
        $subscription = $this->subscription($user, 'https://dupe.example/feed.xml');
        $this->em->flush();

        $id = (int) $subscription->getId();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve([$id, $id], (int) $user->getId());
    }
}

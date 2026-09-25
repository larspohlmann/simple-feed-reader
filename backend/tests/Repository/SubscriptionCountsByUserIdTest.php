<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionCountsByUserId;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionCountsByUserIdTest extends DbTestCase
{
    private function counts(): SubscriptionCountsByUserId
    {
        $counts = self::getContainer()->get(SubscriptionCountsByUserId::class);
        self::assertInstanceOf(SubscriptionCountsByUserId::class, $counts);

        return $counts;
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed): void
    {
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
    }

    public function testAnEmptyIdListShortCircuitsBeforeAnyQueryRuns(): void
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        self::assertSame([], $this->counts()->forUserIds([]));

        self::assertSame(
            [],
            $recorder->queriesMatching('subscription'),
            'an empty id list must short-circuit before any query runs — an empty IN () is a syntax error.',
        );
    }

    public function testCountsEachUsersOwnSubscriptionsInOneQuery(): void
    {
        $first = $this->user('subscription-counts-first@example.com');
        $second = $this->user('subscription-counts-second@example.com');
        $this->subscribe($first, $this->feed('https://a.example/feed.xml'));
        $this->subscribe($first, $this->feed('https://b.example/feed.xml'));
        $this->subscribe($second, $this->feed('https://c.example/feed.xml'));
        $this->em->flush();

        $counts = $this->counts()->forUserIds([$first->requireId(), $second->requireId()]);

        self::assertSame(
            [$first->requireId() => 2, $second->requireId() => 1],
            $counts,
        );
    }

    public function testAUserWithNoSubscriptionsIsAbsentRatherThanZero(): void
    {
        $user = $this->user('subscription-counts-none@example.com');
        $this->em->flush();

        self::assertSame([], $this->counts()->forUserIds([$user->requireId()]));
    }
}

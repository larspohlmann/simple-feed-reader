<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionTagPositions;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class SubscriptionTagPositionsTest extends DbTestCase
{
    use SeedsUsers;

    private function positions(): SubscriptionTagPositions
    {
        $positions = self::getContainer()->get(SubscriptionTagPositions::class);
        self::assertInstanceOf(SubscriptionTagPositions::class, $positions);

        return $positions;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }

    public function testNextForTagSeedsAtZeroWhenTheTagHasNoJoinsYet(): void
    {
        $user = $this->user('tag-positions-empty@example.com');
        $tag = $this->tag($user, 'Empty');
        $this->entityManager->flush();

        self::assertSame(0, $this->positions()->nextForTag($tag));
    }

    /** Three calls draw the exact ascending sequence from the database seed, not merely three distinct numbers. */
    public function testNextForTagHandsOutTheExactAscendingSequenceSeededFromTheDatabase(): void
    {
        $user = $this->user('tag-positions-seed@example.com');
        $tag = $this->tag($user, 'News');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $this->entityManager->persist($existing);
        $existing->addTag($tag, 0);
        $this->entityManager->flush();

        $positions = $this->positions();

        self::assertSame(1, $positions->nextForTag($tag));
        self::assertSame(2, $positions->nextForTag($tag));
        self::assertSame(3, $positions->nextForTag($tag));
    }

    public function testNextUntaggedForUserSeedsAtZeroWhenTheUserHasNoSubscriptionsYet(): void
    {
        $user = $this->user('untagged-positions-empty@example.com');
        $this->entityManager->flush();

        self::assertSame(0, $this->positions()->nextUntaggedForUser($user->requireId()));
    }

    /** The exact sequence from the seed: distinct values alone would not pin the counter to where it must start. */
    public function testNextUntaggedForUserHandsOutTheExactAscendingSequenceSeededFromTheDatabase(): void
    {
        $user = $this->user('untagged-positions-seed@example.com');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $existing->setPosition(4);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $positions = $this->positions();

        self::assertSame(5, $positions->nextUntaggedForUser($user->requireId()));
        self::assertSame(6, $positions->nextUntaggedForUser($user->requireId()));
        self::assertSame(7, $positions->nextUntaggedForUser($user->requireId()));
    }

    public function testTheTwoCountersAreIndependent(): void
    {
        $user = $this->user('independent-counters@example.com');
        $tag = $this->tag($user, 'Independent');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $existing->setPosition(9);
        $this->entityManager->persist($existing);
        $existing->addTag($tag, 2);
        $this->entityManager->flush();

        $positions = $this->positions();

        self::assertSame(10, $positions->nextUntaggedForUser($user->requireId()));
        self::assertSame(3, $positions->nextForTag($tag));
    }
}

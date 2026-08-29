<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionTagPositions;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionTagPositionsTest extends DbTestCase
{
    private function positions(): SubscriptionTagPositions
    {
        $positions = self::getContainer()->get(SubscriptionTagPositions::class);
        self::assertInstanceOf(SubscriptionTagPositions::class, $positions);

        return $positions;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->em->persist($tag);

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
        $this->em->flush();

        self::assertSame(0, $this->positions()->nextForTag($tag));
    }

    /**
     * The whole point of this class: BulkSubscriptionUpdater calls sync() once
     * per subscription and flushes only once after the loop, so a repeated
     * MAX(position) query would return the same stale value for every feed.
     * Calling nextForTag() three times in a row, as three loop iterations
     * would, must hand out the exact ascending sequence seeded from the
     * database — not merely three distinct numbers.
     */
    public function testNextForTagHandsOutTheExactAscendingSequenceSeededFromTheDatabase(): void
    {
        $user = $this->user('tag-positions-seed@example.com');
        $tag = $this->tag($user, 'News');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $this->em->persist($existing);
        $existing->addTag($tag, 0);
        $this->em->flush();

        $positions = $this->positions();

        self::assertSame(1, $positions->nextForTag($tag));
        self::assertSame(2, $positions->nextForTag($tag));
        self::assertSame(3, $positions->nextForTag($tag));
    }

    public function testNextUntaggedForUserSeedsAtZeroWhenTheUserHasNoSubscriptionsYet(): void
    {
        $user = $this->user('untagged-positions-empty@example.com');
        $this->em->flush();

        self::assertSame(0, $this->positions()->nextUntaggedForUser((int) $user->getId()));
    }

    /**
     * The #659 fix itself: nextUntaggedForUser() used to be a fresh MAX()
     * query every call, so a bulk request stripping the last tag from several
     * feeds in one flush-less loop gave every feed the SAME position. Assert
     * the exact ascending sequence, not merely that the results are distinct
     * — distinctness alone does not pin the counter to the seed it must start
     * counting from.
     */
    public function testNextUntaggedForUserHandsOutTheExactAscendingSequenceSeededFromTheDatabase(): void
    {
        $user = $this->user('untagged-positions-seed@example.com');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $existing->setPosition(4);
        $this->em->persist($existing);
        $this->em->flush();

        $positions = $this->positions();

        self::assertSame(5, $positions->nextUntaggedForUser((int) $user->getId()));
        self::assertSame(6, $positions->nextUntaggedForUser((int) $user->getId()));
        self::assertSame(7, $positions->nextUntaggedForUser((int) $user->getId()));
    }

    /**
     * The two counters are keyed separately (by tag id, by user id) and must
     * never share state: seeding one must not perturb the other.
     */
    public function testTheTwoCountersAreIndependent(): void
    {
        $user = $this->user('independent-counters@example.com');
        $tag = $this->tag($user, 'Independent');
        $existing = new Subscription($user, $this->feed('https://a.example/feed.xml'), $this->now());
        $existing->setPosition(9);
        $this->em->persist($existing);
        $existing->addTag($tag, 2);
        $this->em->flush();

        $positions = $this->positions();

        self::assertSame(10, $positions->nextUntaggedForUser((int) $user->getId()));
        self::assertSame(3, $positions->nextForTag($tag));
    }
}

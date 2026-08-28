<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionTagSync;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionTagSyncTest extends DbTestCase
{
    public function testKeepsAKeptTagAtItsPositionAndAppendsANewTag(): void
    {
        $user = $this->user('keeper@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');

        // Two feeds already sit under News: the one under test at 0, another at
        // 1, so News' next append position is 2. A clear-and-re-add would move
        // the kept feed to 2; a diff must leave it at 0.
        $feed = $this->taggedSubscription($user, 'https://a.example.com/rss', [[$news, 0]]);
        $this->taggedSubscription($user, 'https://b.example.com/rss', [[$news, 1]]);

        $this->sync()->sync($feed, [(int) $news->getId(), (int) $tech->getId()], (int) $user->getId());
        $this->em->flush();

        self::assertSame(0, $this->joinPosition($feed, $news));
        self::assertSame(0, $this->joinPosition($feed, $tech));
    }

    public function testRemovesATagNoLongerRequested(): void
    {
        $user = $this->user('remover@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $feed = $this->taggedSubscription($user, 'https://a.example.com/rss', [[$news, 0], [$tech, 0]]);

        $this->sync()->sync($feed, [(int) $news->getId()], (int) $user->getId());
        $this->em->flush();

        $tagNames = array_map(static fn (Tag $t): string => $t->getName(), $feed->getTags()->toArray());
        self::assertSame(['News'], $tagNames);
    }

    public function testAppendsToTheFeedsListWhenTheFeedLosesItsLastTag(): void
    {
        $user = $this->user('lastclearer@example.com');
        $news = $this->tag($user, 'News');
        $feed = $this->taggedSubscription($user, 'https://a.example.com/rss', [[$news, 0]]);
        $feed->setPosition(0); // stale untagged position
        // An untagged sibling at 3 makes the user's next feeds-list slot 4.
        $sibling = new Subscription($user, $this->feed('https://sibling.example.com/rss'), $this->now());
        $sibling->setPosition(3);
        $this->em->persist($sibling);
        $this->em->flush();

        $this->sync()->sync($feed, [], (int) $user->getId());
        $this->em->flush();

        self::assertTrue($feed->getTags()->isEmpty());
        self::assertSame(4, $feed->getPosition());
    }

    public function testLeavesTheFeedsListPositionAloneWhenTheFeedWasAlreadyUntagged(): void
    {
        $user = $this->user('stayput@example.com');
        $feed = new Subscription($user, $this->feed('https://a.example.com/rss'), $this->now());
        $feed->setPosition(0);
        $this->em->persist($feed);
        // A sibling at 7 would push a wrongful append to 8.
        $sibling = new Subscription($user, $this->feed('https://sibling.example.com/rss'), $this->now());
        $sibling->setPosition(7);
        $this->em->persist($sibling);
        $this->em->flush();

        $this->sync()->sync($feed, [], (int) $user->getId());
        $this->em->flush();

        self::assertSame(0, $feed->getPosition());
    }

    public function testIgnoresTagIdsOwnedByAnotherUser(): void
    {
        $user = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $strangerTag = $this->tag($stranger, 'Theirs');
        $feed = new Subscription($user, $this->feed('https://a.example.com/rss'), $this->now());
        $this->em->persist($feed);
        $this->em->flush();

        $this->sync()->sync($feed, [(int) $strangerTag->getId()], (int) $user->getId());
        $this->em->flush();

        self::assertTrue($feed->getTags()->isEmpty());
    }

    private function sync(): SubscriptionTagSync
    {
        $sync = self::getContainer()->get(SubscriptionTagSync::class);
        self::assertInstanceOf(SubscriptionTagSync::class, $sync);

        return $sync;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    /**
     * @param list<array{0: Tag, 1: int}> $tagPositions Tag with its join position
     */
    private function taggedSubscription(User $user, string $url, array $tagPositions): Subscription
    {
        $subscription = new Subscription($user, $this->feed($url), $this->now());
        $this->em->persist($subscription);
        foreach ($tagPositions as [$tag, $position]) {
            $subscription->addTag($tag, $position);
        }
        $this->em->flush();

        return $subscription;
    }

    private function joinPosition(Subscription $subscription, Tag $tag): int
    {
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag() === $tag) {
                return $join->getPosition();
            }
        }
        self::fail('Subscription is not tagged with ' . $tag->getName());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }
}

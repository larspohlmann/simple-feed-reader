<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\FeedTagMove;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeedTagMoveTest extends DbTestCase
{
    public function testInsertsAtIndexAndShiftsTheTargetTagsFeedsDown(): void
    {
        $user = $this->user('inserter@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $x = $this->taggedSubscription($user, 'https://x.example.com/rss', [[$tech, 0]]);
        $y = $this->taggedSubscription($user, 'https://y.example.com/rss', [[$tech, 1]]);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0]]);

        $this->move($moved, (int) $news->getId(), (int) $tech->getId(), 1, (int) $user->getId());
        $this->em->flush();

        self::assertSame(0, $this->joinPosition($x, $tech));
        self::assertSame(1, $this->joinPosition($moved, $tech));
        self::assertSame(2, $this->joinPosition($y, $tech));
        self::assertSame(['Tech'], $this->tagNames($moved));
    }

    public function testAppendsWhenNoPositionIsGiven(): void
    {
        $user = $this->user('appender@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->taggedSubscription($user, 'https://x.example.com/rss', [[$tech, 0]]);
        $this->taggedSubscription($user, 'https://y.example.com/rss', [[$tech, 1]]);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0]]);

        $this->move($moved, (int) $news->getId(), (int) $tech->getId(), null, (int) $user->getId());
        $this->em->flush();

        self::assertSame(2, $this->joinPosition($moved, $tech));
    }

    public function testRepositionsAFeedAlreadyInTheTargetTagWithoutDuplicating(): void
    {
        $user = $this->user('deduper@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $x = $this->taggedSubscription($user, 'https://x.example.com/rss', [[$tech, 0]]);
        $y = $this->taggedSubscription($user, 'https://y.example.com/rss', [[$tech, 1]]);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0], [$tech, 2]]);

        $this->move($moved, (int) $news->getId(), (int) $tech->getId(), 0, (int) $user->getId());
        $this->em->flush();

        self::assertSame(0, $this->joinPosition($moved, $tech));
        self::assertSame(1, $this->joinPosition($x, $tech));
        self::assertSame(2, $this->joinPosition($y, $tech));
        self::assertSame(['Tech'], $this->tagNames($moved));
    }

    public function testRemovesTheSourceTag(): void
    {
        $user = $this->user('remover@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0]]);

        $this->move($moved, (int) $news->getId(), (int) $tech->getId(), 0, (int) $user->getId());
        $this->em->flush();

        self::assertSame(['Tech'], $this->tagNames($moved));
    }

    public function testPlacesInTheUntaggedListAtIndexWhenTheLastTagIsRemoved(): void
    {
        $user = $this->user('untagger@example.com');
        $news = $this->tag($user, 'News');
        $first = $this->untaggedSubscription($user, 'https://a.example.com/rss', 0);
        $second = $this->untaggedSubscription($user, 'https://b.example.com/rss', 1);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0]]);

        $this->move($moved, (int) $news->getId(), null, 1, (int) $user->getId());
        $this->em->flush();

        self::assertTrue($moved->getTags()->isEmpty());
        self::assertSame(0, $first->getPosition());
        self::assertSame(1, $moved->getPosition());
        self::assertSame(2, $second->getPosition());
    }

    public function testClampsAPositionBeyondTheListToTheEnd(): void
    {
        $user = $this->user('clamper@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $this->taggedSubscription($user, 'https://x.example.com/rss', [[$tech, 0]]);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$news, 0]]);

        $this->move($moved, (int) $news->getId(), (int) $tech->getId(), 99, (int) $user->getId());
        $this->em->flush();

        self::assertSame(1, $this->joinPosition($moved, $tech));
    }

    public function testDoesNothingWhenTheSourceAndTargetAreTheSameTag(): void
    {
        $user = $this->user('same@example.com');
        $tech = $this->tag($user, 'Tech');
        $this->taggedSubscription($user, 'https://x.example.com/rss', [[$tech, 0]]);
        $moved = $this->taggedSubscription($user, 'https://m.example.com/rss', [[$tech, 1]]);

        $this->move($moved, (int) $tech->getId(), (int) $tech->getId(), 0, (int) $user->getId());
        $this->em->flush();

        self::assertSame(1, $this->joinPosition($moved, $tech));
        self::assertSame(['Tech'], $this->tagNames($moved));
    }

    public function testRejectsATagTheUserDoesNotOwn(): void
    {
        $user = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $foreignTag = $this->tag($stranger, 'Theirs');
        $moved = $this->untaggedSubscription($user, 'https://m.example.com/rss', 0);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->move($moved, null, (int) $foreignTag->getId(), 0, (int) $user->getId());
    }

    private function move(
        Subscription $subscription,
        ?int $fromTagId,
        ?int $toTagId,
        ?int $position,
        int $userId,
    ): void {
        $service = self::getContainer()->get(FeedTagMove::class);
        self::assertInstanceOf(FeedTagMove::class, $service);
        $service->move($subscription, $fromTagId, $toTagId, $position, $userId);
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

    /**
     * @param list<array{0: Tag, 1: int}> $tagPositions
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

    private function untaggedSubscription(User $user, string $url, int $position): Subscription
    {
        $subscription = new Subscription($user, $this->feed($url), $this->now());
        $subscription->setPosition($position);
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
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

    /** @return list<string> */
    private function tagNames(Subscription $subscription): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        ));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }
}

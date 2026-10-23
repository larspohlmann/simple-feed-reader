<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidSelectionException;
use App\Repository\TagRepository;
use App\Service\Tag\TagOrdering;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class TagOrderingTest extends DbTestCase
{
    use SeedsUsers;

    public function testReorderGivesEachTagItsIndexAndReturnsThemInThatOrder(): void
    {
        $user = $this->user('tag-orderer@example.com');
        $first = $this->tag($user, 'A', 0);
        $second = $this->tag($user, 'B', 1);
        $third = $this->tag($user, 'C', 2);

        $ordered = $this->ordering()->reorder(
            $user,
            [$third->requireId(), $first->requireId(), $second->requireId()],
        );

        self::assertSame([$third, $first, $second], $ordered);
        $this->entityManager->clear();
        self::assertSame(['C', 'A', 'B'], $this->tagNamesInOrder($user));
    }

    public function testReorderRefusesAListThatIsNotExactlyOneSiblingSet(): void
    {
        $user = $this->user('tag-partial@example.com');
        $first = $this->tag($user, 'A', 0);
        $this->tag($user, 'B', 1);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage('tagIds must list exactly one tag and all of its siblings.');
        $this->ordering()->reorder($user, [$first->requireId()]);
    }

    public function testOrderFeedsGivesEachFeedItsIndexWithinTheTag(): void
    {
        $user = $this->user('tag-feed-orderer@example.com');
        $tag = $this->tag($user, 'News', 0);
        $first = $this->taggedSubscription($user, 'https://first.tag-ordering.example.com/rss', $tag, 0);
        $second = $this->taggedSubscription($user, 'https://second.tag-ordering.example.com/rss', $tag, 1);

        $this->ordering()->orderFeeds(
            $tag,
            [$second->requireId(), $first->requireId()],
        );

        $this->entityManager->clear();
        self::assertSame(1, $this->joinPosition($first->requireId(), $tag->requireId()));
        self::assertSame(0, $this->joinPosition($second->requireId(), $tag->requireId()));
    }

    public function testOrderFeedsRefusesFeedsTheTagDoesNotCarry(): void
    {
        $user = $this->user('tag-feed-stranger@example.com');
        $tag = $this->tag($user, 'News', 0);
        $this->taggedSubscription($user, 'https://carried.tag-ordering.example.com/rss', $tag, 0);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage("subscriptionIds must list exactly this tag's feeds.");
        $this->ordering()->orderFeeds($tag, [999999]);
    }

    public function testReorderingTheRootsLeavesTheChildrenAlone(): void
    {
        $user = $this->user('tag-root-orderer@example.com');
        $first = $this->tag($user, 'A', 0);
        $second = $this->tag($user, 'B', 1);
        $child = $this->childTag($user, 'A1', 2, $first);

        $ordered = $this->ordering()->reorder($user, [$second->requireId(), $first->requireId()]);

        self::assertSame([$second, $first], $ordered);
        $this->entityManager->clear();
        self::assertSame(['B', 'A', 'A1'], $this->tagNamesInOrder($user));
        self::assertSame(2, $this->positionOf($child));
    }

    public function testReorderingAParentsChildrenTouchesOnlyThem(): void
    {
        $user = $this->user('tag-child-orderer@example.com');
        $parent = $this->tag($user, 'A', 0);
        $firstChild = $this->childTag($user, 'A1', 1, $parent);
        $secondChild = $this->childTag($user, 'A2', 2, $parent);

        $this->ordering()->reorder($user, [$secondChild->requireId(), $firstChild->requireId()]);

        $this->entityManager->clear();
        self::assertSame(0, $this->positionOf($parent));
        self::assertSame(1, $this->positionOf($firstChild));
        self::assertSame(0, $this->positionOf($secondChild));
    }

    public function testReorderRefusesAMixOfRootsAndChildren(): void
    {
        $user = $this->user('tag-mixed-orderer@example.com');
        $parent = $this->tag($user, 'A', 0);
        $child = $this->childTag($user, 'A1', 1, $parent);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage('tagIds must list exactly one tag and all of its siblings.');
        $this->ordering()->reorder($user, [$child->requireId(), $parent->requireId()]);
    }

    private function childTag(User $user, string $name, int $position, Tag $parent): Tag
    {
        $child = $this->tag($user, $name, $position);
        $child->setParent($parent);
        $this->entityManager->flush();

        return $child;
    }

    private function positionOf(Tag $tag): int
    {
        $reloaded = $this->entityManager->find(Tag::class, $tag->requireId());
        self::assertInstanceOf(Tag::class, $reloaded);

        return $reloaded->getPosition();
    }

    private function ordering(): TagOrdering
    {
        $ordering = self::getContainer()->get(TagOrdering::class);
        self::assertInstanceOf(TagOrdering::class, $ordering);

        return $ordering;
    }

    private function tag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private function taggedSubscription(User $user, string $url, Tag $tag, int $position): Subscription
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $subscription->addTag($tag, $position);
        $this->entityManager->flush();

        return $subscription;
    }

    /** @return list<string> */
    private function tagNamesInOrder(User $user): array
    {
        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);

        return array_map(static fn (Tag $tag): string => $tag->getName(), $tags->findForUser($user->requireId()));
    }

    private function joinPosition(int $subscriptionId, int $tagId): int
    {
        $subscription = $this->entityManager->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag()->requireId() === $tagId) {
                return $join->getPosition();
            }
        }
        self::fail('The subscription does not carry the tag.');
    }
}

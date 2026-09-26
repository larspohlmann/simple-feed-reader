<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidSelectionException;
use App\Repository\TagRepository;
use App\Service\Tag\TagOrdering;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TagOrderingTest extends DbTestCase
{
    public function testReorderGivesEachTagItsIndexAndReturnsThemInThatOrder(): void
    {
        $user = $this->user('tag-orderer@example.com');
        $first = $this->tag($user, 'A', 0);
        $second = $this->tag($user, 'B', 1);
        $third = $this->tag($user, 'C', 2);

        $ordered = $this->ordering()->reorder(
            $user,
            new ReorderTagsRequest([$third->requireId(), $first->requireId(), $second->requireId()]),
        );

        self::assertSame([$third, $first, $second], $ordered);
        $this->em->clear();
        self::assertSame(['C', 'A', 'B'], $this->tagNamesInOrder($user));
    }

    public function testReorderRefusesAListThatIsNotExactlyTheUsersTags(): void
    {
        $user = $this->user('tag-partial@example.com');
        $first = $this->tag($user, 'A', 0);
        $this->tag($user, 'B', 1);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage('tagIds must list exactly your tags.');
        $this->ordering()->reorder($user, new ReorderTagsRequest([$first->requireId()]));
    }

    public function testOrderFeedsGivesEachFeedItsIndexWithinTheTag(): void
    {
        $user = $this->user('tag-feed-orderer@example.com');
        $tag = $this->tag($user, 'News', 0);
        $first = $this->taggedSubscription($user, 'https://first.tag-ordering.example.com/rss', $tag, 0);
        $second = $this->taggedSubscription($user, 'https://second.tag-ordering.example.com/rss', $tag, 1);

        $this->ordering()->orderFeeds(
            $tag,
            new TagFeedOrderRequest([$second->requireId(), $first->requireId()]),
        );

        $this->em->clear();
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
        $this->ordering()->orderFeeds($tag, new TagFeedOrderRequest([999999]));
    }

    private function ordering(): TagOrdering
    {
        $ordering = self::getContainer()->get(TagOrdering::class);
        self::assertInstanceOf(TagOrdering::class, $ordering);

        return $ordering;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function tag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function taggedSubscription(User $user, string $url, Tag $tag, int $position): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $subscription->addTag($tag, $position);
        $this->em->flush();

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
        $subscription = $this->em->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag()->requireId() === $tagId) {
                return $join->getPosition();
            }
        }
        self::fail('The subscription does not carry the tag.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscriptionUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BulkSubscriptionUpdaterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BulkSubscriptionUpdater $updater;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $updater = self::getContainer()->get(BulkSubscriptionUpdater::class);
        self::assertInstanceOf(BulkSubscriptionUpdater::class, $updater);
        $this->updater = $updater;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function tag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->em->persist($tag);

        return $tag;
    }

    private function subscription(User $user, string $url, ?Tag $tag = null, int $tagPosition = 0): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        if (null !== $tag) {
            $subscription->addTag($tag, $tagPosition);
        }
        $this->em->persist($subscription);

        return $subscription;
    }

    /** @return list<string> */
    private function tagNames(Subscription $subscription): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        ));
    }

    public function testAddsATagToEveryListedFeed(): void
    {
        $user = $this->user('bulk-add@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $first = $this->subscription($user, 'https://a.example/feed.xml');
        $second = $this->subscription($user, 'https://b.example/feed.xml');
        $this->em->flush();

        $changed = $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $first->getId(), (int) $second->getId()],
                addTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        self::assertCount(2, $changed);
        self::assertSame(['Tech'], $this->tagNames($first));
        self::assertSame(['Tech'], $this->tagNames($second));
    }

    public function testAFeedThatAlreadyCarriesTheTagKeepsItsPosition(): void
    {
        $user = $this->user('bulk-idempotent@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $first = $this->subscription($user, 'https://a.example/feed.xml', $tech, 0);
        $second = $this->subscription($user, 'https://b.example/feed.xml', $tech, 1);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $first->getId()],
                addTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        $this->em->refresh($first);
        $this->em->refresh($second);
        self::assertSame(['Tech'], $this->tagNames($first));
        self::assertSame(['Tech'], $this->tagNames($second));
    }

    public function testRemovingTheLastTagAppendsTheFeedToTheUntaggedList(): void
    {
        $user = $this->user('bulk-last-tag@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $untagged = $this->subscription($user, 'https://untagged.example/feed.xml');
        $untagged->setPosition(0);
        $tagged = $this->subscription($user, 'https://tagged.example/feed.xml', $tech, 0);
        $tagged->setPosition(0);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $tagged->getId()],
                removeTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        self::assertSame([], $this->tagNames($tagged));
        self::assertGreaterThan(
            $untagged->getPosition(),
            $tagged->getPosition(),
            'A feed that lost its last tag must be appended, not left at a stale position.',
        );
    }

    public function testAppliesOnlyTheFlagsThatAreNotNull(): void
    {
        $user = $this->user('bulk-flags@example.com');
        $subscription = $this->subscription($user, 'https://flags.example/feed.xml');
        $subscription->setIncludeInForYou(false);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                includeInAllItems: false,
            ),
            (int) $user->getId(),
        );

        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou(), 'A null flag must not be written.');
    }

    public function testRejectsATagNamedInBothAddAndRemove(): void
    {
        $user = $this->user('bulk-contradiction@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $subscription = $this->subscription($user, 'https://c.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                addTagIds: [(int) $tech->getId()],
                removeTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );
    }

    public function testRejectsATagThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('bulk-mine@example.com');
        $theirs = $this->user('bulk-theirs@example.com');
        $foreignTag = $this->tag($theirs, 'Theirs', 0);
        $subscription = $this->subscription($mine, 'https://d.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                addTagIds: [(int) $foreignTag->getId()],
            ),
            (int) $mine->getId(),
        );
    }

    public function testRejectsASubscriptionThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('bulk-sub-mine@example.com');
        $theirs = $this->user('bulk-sub-theirs@example.com');
        $foreign = $this->subscription($theirs, 'https://e.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(subscriptionIds: [(int) $foreign->getId()]),
            (int) $mine->getId(),
        );
    }
}

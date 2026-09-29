<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SubscriptionTest extends DbTestCase
{
    private function makeUser(string $email = 'reader@example.com'): User
    {
        $user = new User($email, new \DateTimeImmutable());
        $this->entityManager->persist($user);

        return $user;
    }

    private function makeFeed(string $url = 'https://example.com/feed.xml'): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    public function testSubscriptionWithMultipleTags(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();

        $tech = new Tag($user, 'Tech');
        $tech->setColor('#3366ff');
        $tech->setIcon('memory');
        $linux = new Tag($user, 'Linux');
        $this->entityManager->persist($tech);
        $this->entityManager->persist($linux);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable());
        $subscription->addTag($tech);
        $subscription->addTag($linux);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user->getId()]);

        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getTags());
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    public function testUserCannotSubscribeTwiceToSameFeed(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $now = new \DateTimeImmutable();

        $this->entityManager->persist(new Subscription($user, $feed, $now));
        $this->entityManager->flush();

        $this->entityManager->persist(new Subscription($user, $feed, $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testTagNameUniquePerUserButNotGlobally(): void
    {
        $userA = $this->makeUser('a@example.com');
        $userB = $this->makeUser('b@example.com');

        $this->entityManager->persist(new Tag($userA, 'News'));
        $this->entityManager->persist(new Tag($userB, 'News'));
        $this->entityManager->flush();

        $this->entityManager->persist(new Tag($userA, 'News'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testEntryStateCompositeKey(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $now = new \DateTimeImmutable();
        $entry = new Entry($feed, 'guid-1', 'https://example.com/1', 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $state = new EntryState($user, $entry);
        $state->hide(new \DateTimeImmutable());
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            EntryState::class,
            ['user' => $user->getId(), 'entry' => $entry->getId()],
        );

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isHidden());
        self::assertFalse($reloaded->isFavorite());
        self::assertFalse($reloaded->isKept());
    }

    public function testDeletingTagRemovesItFromSubscriptions(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $tag = new Tag($user, 'Doomed');
        $this->entityManager->persist($tag);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable());
        $subscription->addTag($tag);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        $subscriptionId = $subscription->getId();

        $this->entityManager->remove($tag);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Subscription::class, $subscriptionId);

        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getTags());
    }

    public function testEntryStateFavoriteAndKeptRoundTrip(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $now = new \DateTimeImmutable();
        $entry = new Entry($feed, 'guid-2', 'https://example.com/2', 'Keeper', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $state = new EntryState($user, $entry);
        $state->markFavorite();
        $state->markKept();
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(
            EntryState::class,
            ['user' => $user->getId(), 'entry' => $entry->getId()],
        );

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isFavorite());
        self::assertTrue($reloaded->isKept());
        self::assertFalse($reloaded->isHidden());
    }

    public function testANewSubscriptionIsIncludedEverywhereByDefault(): void
    {
        $subscription = new Subscription($this->makeUser(), $this->makeFeed(), new \DateTimeImmutable());

        self::assertTrue($subscription->isIncludeInAllItems());
        self::assertTrue($subscription->isIncludeInForYou());
    }

    public function testExclusionFlagsCanBeToggled(): void
    {
        $subscription = new Subscription($this->makeUser(), $this->makeFeed(), new \DateTimeImmutable());
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);

        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
    }
}

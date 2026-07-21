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
        $this->em->persist($user);

        return $user;
    }

    private function makeFeed(string $url = 'https://example.com/feed.xml'): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

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
        $this->em->persist($tech);
        $this->em->persist($linux);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable());
        $subscription->addTag($tech);
        $subscription->addTag($linux);
        $this->em->persist($subscription);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $user->getId()]);

        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getTags());
        self::assertNull($reloaded->getMarkedReadUntil());
    }

    public function testUserCannotSubscribeTwiceToSameFeed(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $now = new \DateTimeImmutable();

        $this->em->persist(new Subscription($user, $feed, $now));
        $this->em->flush();

        $this->em->persist(new Subscription($user, $feed, $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testTagNameUniquePerUserButNotGlobally(): void
    {
        $userA = $this->makeUser('a@example.com');
        $userB = $this->makeUser('b@example.com');

        $this->em->persist(new Tag($userA, 'News'));
        $this->em->persist(new Tag($userB, 'News'));
        $this->em->flush();

        $this->em->persist(new Tag($userA, 'News'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testEntryStateCompositeKey(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $entry = new Entry($feed, 'guid-1', 'https://example.com/1', 'Post', new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();

        $state = new EntryState($user, $entry);
        $state->setIsRead(true);
        $state->setReadAt(new \DateTimeImmutable());
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(EntryState::class, ['user' => $user->getId(), 'entry' => $entry->getId()]);

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRead());
        self::assertFalse($reloaded->isFavorite());
        self::assertFalse($reloaded->isKept());
    }

    public function testDeletingTagRemovesItFromSubscriptions(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $tag = new Tag($user, 'Doomed');
        $this->em->persist($tag);

        $subscription = new Subscription($user, $feed, new \DateTimeImmutable());
        $subscription->addTag($tag);
        $this->em->persist($subscription);
        $this->em->flush();
        $subscriptionId = $subscription->getId();

        $this->em->remove($tag);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Subscription::class, $subscriptionId);

        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getTags());
    }

    public function testEntryStateFavoriteAndKeptRoundTrip(): void
    {
        $user = $this->makeUser();
        $feed = $this->makeFeed();
        $entry = new Entry($feed, 'guid-2', 'https://example.com/2', 'Keeper', new \DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();

        $state = new EntryState($user, $entry);
        $state->setIsFavorite(true);
        $state->setIsKept(true);
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(EntryState::class, ['user' => $user->getId(), 'entry' => $entry->getId()]);

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isFavorite());
        self::assertTrue($reloaded->isKept());
        self::assertFalse($reloaded->isRead());
    }
}

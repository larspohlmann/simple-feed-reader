<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Tests\DbTestCase;

final class MarkedReadUntilForUserEntryTest extends DbTestCase
{
    private function repo(): EntryStateRepository
    {
        $repo = $this->em->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function entryInFeed(Feed $feed): Entry
    {
        $entry = new Entry($feed, 'g', null, 'Post', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $entry->setPublishedAt(new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $this->em->persist($entry);

        return $entry;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    public function testReturnsTheWatermarkOfTheSubscriptionCarryingTheEntry(): void
    {
        $user = $this->user('watermark@example.com');
        $feed = $this->feed('https://example.com/watermark.xml');
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($sub);
        $entry = $this->entryInFeed($feed);
        $this->em->flush();

        $watermark = $this->repo()->markedReadUntilForUserEntry(
            (int) $user->getId(),
            (int) $entry->getId(),
        );

        self::assertNotNull($watermark);
        self::assertSame('2026-07-10T00:00:00+00:00', $watermark->format(\DateTimeInterface::ATOM));
    }

    public function testReturnsNullWhenTheSubscriptionWasNeverSwept(): void
    {
        $user = $this->user('never-swept@example.com');
        $feed = $this->feed('https://example.com/never-swept.xml');
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = $this->entryInFeed($feed);
        $this->em->flush();

        self::assertNull($this->repo()->markedReadUntilForUserEntry(
            (int) $user->getId(),
            (int) $entry->getId(),
        ));
    }

    public function testIgnoresAnotherUsersWatermarkOnTheSameFeed(): void
    {
        $mine = $this->user('mine@example.com');
        $theirs = $this->user('theirs@example.com');
        $feed = $this->feed('https://example.com/shared.xml');
        $myself = new Subscription($mine, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($myself);
        $stranger = new Subscription($theirs, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($stranger);
        $entry = $this->entryInFeed($feed);
        $this->em->flush();

        self::assertNull($this->repo()->markedReadUntilForUserEntry(
            (int) $mine->getId(),
            (int) $entry->getId(),
        ));
    }
}

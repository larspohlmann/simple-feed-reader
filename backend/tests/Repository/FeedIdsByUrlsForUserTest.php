<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

/**
 * EntryPartInspector's subscribed-feed check: a url absent from this map
 * names a feed the user does not subscribe to, whether the feed row exists
 * for someone else or does not exist at all.
 */
final class FeedIdsByUrlsForUserTest extends DbTestCase
{
    public function testReturnsOnlyTheUrlsThisUserSubscribesTo(): void
    {
        $user = new User('feed-ids-by-url@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->em->persist($user);
        $stranger = new User('feed-ids-by-url-stranger@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->em->persist($stranger);

        $mine = new Feed('https://mine.example/feed.xml');
        $theirs = new Feed('https://theirs.example/feed.xml');
        $this->em->persist($mine);
        $this->em->persist($theirs);
        $this->em->persist(new Subscription($user, $mine, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->persist(new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em->flush();

        $result = $this->repository()->idsByUrlsForUser(
            (int) $user->getId(),
            ['https://mine.example/feed.xml', 'https://theirs.example/feed.xml', 'https://unknown.example/feed.xml'],
        );

        self::assertSame(['https://mine.example/feed.xml' => (int) $mine->getId()], $result);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->idsByUrlsForUser(1, []));
    }

    private function repository(): FeedRepository
    {
        $repository = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}

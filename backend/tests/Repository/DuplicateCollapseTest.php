<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
use App\Tests\DbTestCase;

final class DuplicateCollapseTest extends DbTestCase
{
    private User $user;
    private Feed $feedA;
    private Feed $feedB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('dupe@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feedA = $this->feed('https://a.example/feed.xml', 'Feed A');
        $this->feedB = $this->feed('https://b.example/feed.xml', 'Feed B');
        $this->subscribe($this->user, $this->feedA);
        $this->subscribe($this->user, $this->feedB);
        $this->em->flush();
    }

    public function testCrossFeedDuplicateCollapsesToTheLowerId(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'all'));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
        self::assertNotSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testAUserOnOnlyOneSideSeesTheirOwnCopy(): void
    {
        $solo = new User('solo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($solo);
        $this->subscribe($solo, $this->feedB);
        $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $onlyCopy = $this->entry(
            $this->feedB,
            'b-guid',
            'https://tagesschau.de/x',
            'urlhash-x',
            '2026-07-05T10:00:00Z',
        );
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $solo->getId(), view: 'all'));

        self::assertCount(1, $rows);
        self::assertSame($onlyCopy->getId(), $rows[0]->entry->getId());
    }

    public function testUnreadViewSurvivorIsTheUnreadCopyWhenTheLowerIdIsRead(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $read = new EntryState($this->user, $lower);
        $read->setIsHidden(true);
        $this->em->persist($read);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'unread'));

        self::assertCount(1, $rows);
        self::assertSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testNullUrlHashNeverGroups(): void
    {
        $one = $this->entry($this->feedA, 'a-guid', null, null, '2026-07-05T09:00:00Z');
        $two = $this->entry($this->feedB, 'b-guid', null, null, '2026-07-05T10:00:00Z');
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery((int) $this->user->getId(), view: 'all'));

        self::assertCount(2, $rows);
        $ids = array_map(static fn ($r) => $r->entry->getId(), $rows);
        self::assertContains($one->getId(), $ids);
        self::assertContains($two->getId(), $ids);
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function feed(string $url, string $title): Feed
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->em->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($sub);

        return $sub;
    }

    private function entry(Feed $feed, string $guid, ?string $url, ?string $urlHash, string $effective): Entry
    {
        $at = new \DateTimeImmutable($effective);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, $urlHash);
        $this->em->persist($entry);

        return $entry;
    }
}

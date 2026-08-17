<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

/**
 * rowsByIdsForUser: the hydration step IndexedEntrySearch uses to turn a
 * search index's entry ids back into full list rows. It reuses the entry
 * list's own projection and subscription join, which is the point — see the
 * method's docblock on EntryListRepository.
 */
final class EntryRowsByIdsTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->em->flush();
    }

    private function entry(string $guid, string $effectiveDate, ?Feed $feed = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string> the guids the rows resolved to, in the order returned
     */
    private function rowsByIds(array $ids): array
    {
        $rows = $this->repo()->rowsByIdsForUser($ids, $this->user->getId() ?? 0);

        return array_map(static fn ($row) => $row->entry->getGuid(), $rows);
    }

    public function testReturnsTheRowsForTheGivenIds(): void
    {
        $entry = $this->entry('hit', '2026-07-10T00:00:00Z');
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        self::assertSame(['hit'], $this->rowsByIds([$entryId]));
    }

    public function testOrdersNewestFirstRegardlessOfTheIdOrderAsked(): void
    {
        $older = $this->entry('older', '2026-07-10T00:00:00Z');
        $newer = $this->entry('newer', '2026-07-12T00:00:00Z');
        $olderId = $older->getId();
        $newerId = $newer->getId();
        self::assertNotNull($olderId);
        self::assertNotNull($newerId);

        // Ask for the older id first — the returned order must not follow
        // the id order, only effectiveDate/id DESC.
        self::assertSame(['newer', 'older'], $this->rowsByIds([$olderId, $newerId]));
    }

    public function testDropsAnIdInAFeedTheUserDoesNotSubscribeTo(): void
    {
        $other = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($other);
        $this->em->flush();

        $visible = $this->entry('visible', '2026-07-10T00:00:00Z');
        $foreign = $this->entry('foreign', '2026-07-11T00:00:00Z', $other);
        $visibleId = $visible->getId();
        $foreignId = $foreign->getId();
        self::assertNotNull($visibleId);
        self::assertNotNull($foreignId);

        // $foreignId is a real, persisted entry id — it is only the missing
        // subscription that must keep it out. A test that used a nonexistent
        // id here could pass for the wrong reason.
        self::assertSame(['visible'], $this->rowsByIds([$visibleId, $foreignId]));
    }

    public function testAnEmptyIdListReturnsEmptyWithoutAQuery(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        self::assertSame([], $this->repo()->rowsByIdsForUser([], $this->user->getId() ?? 0));
        self::assertSame([], $recorder->queries());
    }
}

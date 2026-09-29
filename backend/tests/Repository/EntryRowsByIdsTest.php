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
 * rowsByIdsForUser(), which turns a search index's ids back into list rows through the entry list's own projection
 * and subscription join.
 */
final class EntryRowsByIdsTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);

        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->entityManager->flush();
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
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function repository(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string> the guids the rows resolved to, in the order returned
     */
    private function rowsByIds(array $ids): array
    {
        $rows = $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids);

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

    public function testTheLimitCapsHydrationToTheNewestRows(): void
    {
        $oldest = $this->entry('oldest', '2026-07-10T00:00:00Z');
        $middle = $this->entry('middle', '2026-07-11T00:00:00Z');
        $newest = $this->entry('newest', '2026-07-12T00:00:00Z');
        $ids = [$oldest->requireId(), $middle->requireId(), $newest->requireId()];

        // A limit keeps only the newest rows, still in newest-first order — the
        // tail past the limit is never hydrated.
        self::assertSame(
            ['newest', 'middle'],
            array_map(
                static fn ($row) => $row->entry->getGuid(),
                $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids, 2),
            ),
        );
        // No limit hydrates every given id.
        self::assertCount(3, $this->repository()->rowsByIdsForUser($this->user->requireId(), $ids));
    }

    public function testDropsAnIdInAFeedTheUserDoesNotSubscribeTo(): void
    {
        $other = new Feed('https://other.example.com/feed.xml');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

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

        self::assertSame([], $this->repository()->rowsByIdsForUser($this->user->requireId(), []));
        self::assertSame([], $recorder->queries());
    }
}

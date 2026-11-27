<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Reading\EntryStateResolver;
use App\Service\Library\Reading\LocalLikes;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StateMarks;
use Symfony\Component\Clock\MockClock;

final class LocalLikesTest extends DbTestCase
{
    use SeedsUsers;

    private User $reader;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = $this->user('local-likes@example.test');
        $this->clock = new MockClock(new \DateTimeImmutable('2026-11-27T10:30:00+01:00'));
    }

    public function testALikeRecordsItsInstantInUtc(): void
    {
        $row = $this->articleRow();

        $this->likes()->like($this->reader, $row);

        self::assertEquals(new \DateTimeImmutable('2026-11-27 09:30:00'), $this->requireState($row)->getLikedAt());
        self::assertTrue($this->rowOf($row)->marks->isLiked);
    }

    public function testALikeAgainKeepsTheFirstInstant(): void
    {
        $row = $this->articleRow();
        $this->likes()->like($this->reader, $row);
        $this->clock->modify('+1 day');

        $this->likes()->like($this->reader, $row);

        self::assertEquals(new \DateTimeImmutable('2026-11-27 09:30:00'), $this->requireState($row)->getLikedAt());
    }

    public function testALikeKeepsTheOtherMarks(): void
    {
        $row = $this->articleRow();
        $this->entityManager->persist(StateMarks::kept(new EntryState($this->reader, $row->entry)));
        $this->entityManager->flush();

        $this->likes()->like($this->reader, $row);

        $state = $this->requireState($row);
        self::assertTrue($state->isLiked());
        self::assertTrue($state->isKept());
    }

    public function testAnUnlikeTakesTheLikeBackAndKeepsTheOtherMarks(): void
    {
        $row = $this->articleRow();
        $state = StateMarks::favorite(new EntryState($this->reader, $row->entry));
        $this->entityManager->persist(StateMarks::liked($state, $this->clock->now()));
        $this->entityManager->flush();

        $this->likes()->unlike($this->reader, $row);

        $state = $this->requireState($row);
        self::assertFalse($state->isLiked());
        self::assertTrue($state->isFavorite());
        self::assertFalse($this->rowOf($row)->marks->isLiked);
    }

    public function testAnUnlikeOfAnEntryNeverTouchedWritesNoState(): void
    {
        $row = $this->articleRow();

        $this->likes()->unlike($this->reader, $row);

        self::assertNull($this->findState($row));
    }

    private function likes(): LocalLikes
    {
        /** @var EntryStateResolver $resolver */
        $resolver = self::getContainer()->get(EntryStateResolver::class);

        $clock = new NaiveUtcClock($this->clock);

        return new LocalLikes($resolver, $this->stateRepository(), $this->entityManager, $clock);
    }

    private function articleRow(): EntryListRow
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $entry = $fixtures->persistPost(
            $fixtures->subscribe($this->reader, NetworkFeedSubscriptions::PLAIN),
            'https://example.com/articles/1',
            '2026-11-26T10:00:00Z',
        );

        return $this->rowOf($entry->requireId());
    }

    private function rowOf(EntryListRow|int $rowOrEntryId): EntryListRow
    {
        $entryId = $rowOrEntryId instanceof EntryListRow ? $rowOrEntryId->entry->requireId() : $rowOrEntryId;
        /** @var EntryListRepository $entryList */
        $entryList = self::getContainer()->get(EntryListRepository::class);

        return $entryList->getRowForUser($this->reader->requireId(), $entryId);
    }

    private function requireState(EntryListRow $row): EntryState
    {
        $state = $this->findState($row);
        self::assertNotNull($state);

        return $state;
    }

    private function findState(EntryListRow $row): ?EntryState
    {
        $this->entityManager->clear();

        return $this->stateRepository()->findOneForUserEntry($this->reader->requireId(), $row->entry->requireId());
    }

    private function stateRepository(): EntryStateRepository
    {
        /** @var EntryStateRepository $states */
        $states = self::getContainer()->get(EntryStateRepository::class);

        return $states;
    }
}

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

final class EntryStateRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('state-repo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    private function repo(): EntryStateRepository
    {
        $repo = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    private function entry(string $guid): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testReturnsExactlyTheEntryIdsThatHaveAStateRow(): void
    {
        $withState = $this->entry('with-state-set');
        $withoutState = $this->entry('without-state-set');

        $state = new EntryState($this->user, $withState);
        $state->markFavorite();
        $this->em->persist($state);
        $this->em->flush();

        $result = $this->repo()->entryIdsWithStateForUser(
            $this->user->requireId(),
            [$withState->requireId(), $withoutState->requireId()],
        );

        self::assertSame([$withState->requireId()], $result);
    }

    public function testEmptyEntryIdListReturnsEmptyWithoutQuerying(): void
    {
        self::assertSame([], $this->repo()->entryIdsWithStateForUser($this->user->requireId(), []));
    }

    public function testForUserByEntryIdsWithEmptyListReturnsEmptyWithoutQuerying(): void
    {
        self::assertSame([], $this->repo()->forUserByEntryIds($this->user->requireId(), []));
    }

    public function testForUserByEntryIdsIsKeyedByEntryIdAndHoldsOnlyTheAskedUsersStates(): void
    {
        $otherUser = new User('state-repo-other@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($otherUser);
        $this->em->persist(
            new Subscription($otherUser, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $shared = $this->entry('shared-guid');
        $withoutState = $this->entry('without-state-guid');

        $mineState = new EntryState($this->user, $shared);
        $mineState->markFavorite();
        $this->em->persist($mineState);

        $theirState = new EntryState($otherUser, $shared);
        $theirState->markKept();
        $this->em->persist($theirState);
        $this->em->flush();

        $result = $this->repo()->forUserByEntryIds(
            $this->user->requireId(),
            [$shared->requireId(), $withoutState->requireId()],
        );

        self::assertSame([$shared->requireId()], array_keys($result));
        self::assertTrue($result[$shared->requireId()]->isFavorite());
        self::assertFalse($result[$shared->requireId()]->isKept());
    }

    public function testEnsureRowIsIdempotentAndLeavesExactlyOneRow(): void
    {
        $entry = $this->entry('ensure-idempotent');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();

        $this->repo()->ensureRow($userId, $entryId, null);
        $this->repo()->ensureRow($userId, $entryId, null);

        self::assertSame(1, $this->repo()->countForUser($userId));
    }

    public function testEnsureRowDoesNotClobberAnExistingRowsFlags(): void
    {
        $entry = $this->entry('ensure-keeps-flags');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();

        $this->repo()->ensureRow($userId, $entryId, null);

        $state = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($state);
        $state->markFavorite();
        $this->em->flush();

        $this->repo()->ensureRow($userId, $entryId, null);

        $this->em->clear();
        $reloaded = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isFavorite());
    }

    public function testEnsureRowSeedsAnEffectivelyReadRowHiddenFromTheWatermark(): void
    {
        $entry = $this->entry('ensure-seed-hidden');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();
        $watermark = new \DateTimeImmutable('2026-07-08T09:30:00');

        $this->repo()->ensureRow($userId, $entryId, $watermark);

        $this->em->clear();
        $state = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertEquals($watermark, $state->getHiddenAt());
    }

    public function testEnsureRowLeavesTheNonReadFlagsAtTheirFalseDefault(): void
    {
        $entry = $this->entry('ensure-flag-defaults');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();

        $this->repo()->ensureRow($userId, $entryId, null);

        $this->em->clear();
        $state = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($state);
        self::assertFalse($state->isFavorite());
        self::assertFalse($state->isKept());
        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testEnsureRowWithoutAHiddenSinceSeedsAnUnreadRow(): void
    {
        $entry = $this->entry('ensure-unread');
        $userId = $this->user->requireId();
        $entryId = $entry->requireId();

        $this->repo()->ensureRow($userId, $entryId, null);

        $this->em->clear();
        $state = $this->repo()->findOneForUserEntry($userId, $entryId);
        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
        self::assertNull($state->getHiddenAt());
    }
}

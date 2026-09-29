<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\EntryStateRepository;
use App\Service\Reading\EntryStateResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class EntryStateResolverTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('resolver@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $feed = new Feed('https://example.com/resolver.xml');
        $feed->setTitle('Resolver Feed');
        $this->entityManager->persist($feed);

        $this->subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->subscription);

        $this->feed = $feed;
        $this->entityManager->flush();
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
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function resolver(): EntryStateResolver
    {
        $service = self::getContainer()->get(EntryStateResolver::class);
        self::assertInstanceOf(EntryStateResolver::class, $service);

        return $service;
    }

    private function repository(): EntryStateRepository
    {
        $repository = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $repository);

        return $repository;
    }

    private function rows(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }

    private function listRow(Entry $entry, bool $isHidden, ?\DateTimeImmutable $markedReadUntil): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription($this->subscription->requireId(), 'Resolver Feed'),
            $isHidden,
            false,
            false,
            new EntryListRowViewState(false, null),
            $markedReadUntil,
        );
    }

    /**
     * A concurrent writer inserts the row after this request resolved it. resolve() reloads the winning row, so the
     * flush issues an UPDATE, not a duplicate-key INSERT.
     */
    public function testResolveSurvivesAConcurrentInsertOfTheSameRow(): void
    {
        $entry = $this->entry('race');
        $row = $this->rows()->getRowForUser($this->user->requireId(), $entry->requireId());

        $state = $this->resolver()->resolve($this->user, $row);
        $state->markFavorite();

        $this->repository()->ensureRow($this->user->requireId(), $entry->requireId(), null);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $persisted = $this->repository()->findOneForUserEntry($this->user->requireId(), $entry->requireId());
        self::assertNotNull($persisted);
        self::assertTrue($persisted->isFavorite());
    }

    public function testResolveSeedsAnEffectivelyReadRowHiddenFromTheWatermark(): void
    {
        $entry = $this->entry('watermark');
        $watermark = new \DateTimeImmutable('2026-07-06T11:15:00');

        $state = $this->resolver()->resolve($this->user, $this->listRow($entry, true, $watermark));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertTrue($state->isHidden());
        $persisted = $this->repository()->findOneForUserEntry($this->user->requireId(), $entry->requireId());
        self::assertNotNull($persisted);
        self::assertTrue($persisted->isHidden());
        self::assertEquals($watermark, $persisted->getHiddenAt());
    }

    public function testResolveReturnsTheExistingRowUnchanged(): void
    {
        $entry = $this->entry('existing');
        $existing = new EntryState($this->user, $entry);
        $existing->markKept();
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $resolved = $this->resolver()->resolve($this->user, $this->listRow($entry, false, null));

        self::assertSame($existing, $resolved);
        self::assertTrue($resolved->isKept());
    }

    public function testResolveSkipsTheInsertWhenTheRowAlreadyExists(): void
    {
        $entry = $this->entry('already-there');
        $this->entityManager->persist(new EntryState($this->user, $entry));
        $this->entityManager->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->resolver()->resolve($this->user, $this->listRow($entry, false, null));

        self::assertCount(
            0,
            $recorder->queriesMatching('insert'),
            "resolve() must not attempt an insert when the row already exists, got:\n"
                . implode("\n", $recorder->queries()),
        );
    }
}

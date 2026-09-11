<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryStateRepository;
use App\Service\Reader\EntryStateResolver;
use App\Tests\DbTestCase;

final class EntryStateResolverTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('resolver@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/resolver.xml');
        $feed->setTitle('Resolver Feed');
        $this->em->persist($feed);

        $this->subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->subscription);

        $this->feed = $feed;
        $this->em->flush();
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

    private function resolver(): EntryStateResolver
    {
        $service = self::getContainer()->get(EntryStateResolver::class);
        self::assertInstanceOf(EntryStateResolver::class, $service);

        return $service;
    }

    private function repo(): EntryStateRepository
    {
        $repo = self::getContainer()->get(EntryStateRepository::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    private function rows(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function listRow(Entry $entry, bool $isHidden, ?\DateTimeImmutable $markedReadUntil): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription((int) $this->subscription->getId(), 'Resolver Feed'),
            $isHidden,
            false,
            false,
            false,
            null,
            $markedReadUntil,
        );
    }

    /**
     * The #496 concurrency bug: two requests touching the same duplicate group
     * both find no state row, both lazily create one, and the second flush dies
     * on the composite primary key. Here the concurrent winner commits the row
     * (through the idempotent insert) after this request already resolved it;
     * the fix reloads the winning row, so the flush issues only an UPDATE.
     */
    public function testResolveSurvivesAConcurrentInsertOfTheSameRow(): void
    {
        $entry = $this->entry('race');
        $row = $this->rows()->oneRowForUser((int) $entry->getId(), (int) $this->user->getId());
        self::assertNotNull($row);

        $state = $this->resolver()->resolve($this->user, $row);
        $state->setIsFavorite(true);

        $this->repo()->ensureRow((int) $this->user->getId(), (int) $entry->getId(), false, null);

        $this->em->flush();
        $this->em->clear();

        $persisted = $this->repo()->findOneForUserEntry((int) $this->user->getId(), (int) $entry->getId());
        self::assertNotNull($persisted);
        self::assertTrue($persisted->isFavorite());
    }

    public function testResolveSeedsAnEffectivelyReadRowHiddenFromTheWatermark(): void
    {
        $entry = $this->entry('watermark');
        $watermark = new \DateTimeImmutable('2026-07-06T11:15:00');

        $state = $this->resolver()->resolve($this->user, $this->listRow($entry, true, $watermark));
        $this->em->flush();
        $this->em->clear();

        self::assertTrue($state->isHidden());
        $persisted = $this->repo()->findOneForUserEntry((int) $this->user->getId(), (int) $entry->getId());
        self::assertNotNull($persisted);
        self::assertTrue($persisted->isHidden());
        self::assertEquals($watermark, $persisted->getHiddenAt());
    }

    public function testResolveReturnsTheExistingRowUnchanged(): void
    {
        $entry = $this->entry('existing');
        $existing = new EntryState($this->user, $entry);
        $existing->setIsKept(true);
        $this->em->persist($existing);
        $this->em->flush();

        $resolved = $this->resolver()->resolve($this->user, $this->listRow($entry, false, null));

        self::assertSame($existing, $resolved);
        self::assertTrue($resolved->isKept());
    }
}

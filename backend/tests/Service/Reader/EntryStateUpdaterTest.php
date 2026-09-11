<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Dto\Entry\UpdateEntryStateRequest;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Service\Reader\EntryStateUpdater;
use App\Tests\DbTestCase;

final class EntryStateUpdaterTest extends DbTestCase
{
    /** @return array{User, Entry, Entry} */
    private function seedGroup(): array
    {
        $user = new User('mirror@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        $feedA = new Feed('https://example.com/mirror-a.xml');
        $this->em->persist($feedA);
        $this->em->persist(new Subscription($user, $feedA, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $feedB = new Feed('https://example.com/mirror-b.xml');
        $this->em->persist($feedB);
        $this->em->persist(new Subscription($user, $feedB, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $effectiveDate = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $target = new Entry(
            $feedA,
            'mirror-target',
            'https://news.example.com/shared',
            'Shared Article A',
            $effectiveDate,
            $effectiveDate,
            'shared-url-hash',
        );
        $this->em->persist($target);
        $sibling = new Entry(
            $feedB,
            'mirror-sibling',
            'https://news.example.com/shared',
            'Shared Article B',
            $effectiveDate,
            $effectiveDate,
            'shared-url-hash',
        );
        $this->em->persist($sibling);
        $this->em->flush();

        return [$user, $target, $sibling];
    }

    private function request(
        ?bool $isHidden = null,
        ?bool $isFavorite = null,
        ?bool $isKept = null,
        ?bool $isViewed = null,
    ): UpdateEntryStateRequest {
        return new UpdateEntryStateRequest($isHidden, $isFavorite, $isKept, $isViewed);
    }

    /**
     * The persisted state, or an unpersisted all-false default when no row was
     * ever created for it — a mirror that only touches isHidden/isViewed
     * leaves an untouched sibling with no row at all.
     */
    private function stateOf(User $user, Entry $entry): EntryState
    {
        return $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry((int) $user->getId(), (int) $entry->getId())
            ?? new EntryState($user, $entry);
    }

    private function rows(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function updater(): EntryStateUpdater
    {
        $service = self::getContainer()->get(EntryStateUpdater::class);
        self::assertInstanceOf(EntryStateUpdater::class, $service);

        return $service;
    }

    public function testReadMirrorsToTheSubscribedSibling(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
        self::assertNotNull($row);

        $this->updater()->apply($user, $row, $this->request(isHidden: true));

        self::assertTrue($this->stateOf($user, $target)->isHidden());
        self::assertTrue($this->stateOf($user, $sibling)->isHidden());
    }

    public function testFavoriteDoesNotMirror(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
        self::assertNotNull($row);

        $this->updater()->apply($user, $row, $this->request(isFavorite: true));

        self::assertTrue($this->stateOf($user, $target)->isFavorite());
        self::assertFalse($this->stateOf($user, $sibling)->isFavorite());
    }

    public function testKeptDoesNotMirror(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
        self::assertNotNull($row);

        $this->updater()->apply($user, $row, $this->request(isKept: true));

        self::assertTrue($this->stateOf($user, $target)->isKept());
        self::assertFalse($this->stateOf($user, $sibling)->isKept());
    }

    public function testViewedMirrorsToTheSubscribedSiblingAndImpliesHiddenThere(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->oneRowForUser((int) $target->getId(), (int) $user->getId());
        self::assertNotNull($row);

        $this->updater()->apply($user, $row, $this->request(isViewed: true));

        self::assertTrue($this->stateOf($user, $target)->isViewed());
        self::assertTrue($this->stateOf($user, $sibling)->isViewed());
        // #482: a viewed row is always hidden, on the mirrored copy too.
        self::assertTrue($this->stateOf($user, $sibling)->isHidden());
    }

    public function testSiblingRowsForUserExcludesTheTargetAndIsNotCollapsed(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();

        $siblingRows = $this->rows()->siblingRowsForUser(
            'shared-url-hash',
            (int) $target->getId(),
            (int) $user->getId(),
        );

        self::assertCount(1, $siblingRows);
        self::assertSame((int) $sibling->getId(), (int) $siblingRows[0]->entry->getId());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Service\Library\Reading\EntryStateUpdater;
use App\Service\Library\Reading\Model\EntryStateChangeModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\StoredUserSettings;

final class EntryStateUpdaterTest extends DbTestCase
{
    /** @return array{User, Entry, Entry} */
    private function seedGroup(): array
    {
        $user = new User('mirror@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);

        $feedA = new Feed('https://example.com/mirror-a.xml');
        $this->entityManager->persist($feedA);
        $this->entityManager->persist(new Subscription($user, $feedA, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $feedB = new Feed('https://example.com/mirror-b.xml');
        $this->entityManager->persist($feedB);
        $this->entityManager->persist(new Subscription($user, $feedB, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

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
        $this->entityManager->persist($target);
        $sibling = new Entry(
            $feedB,
            'mirror-sibling',
            'https://news.example.com/shared',
            'Shared Article B',
            $effectiveDate,
            $effectiveDate,
            'shared-url-hash',
        );
        $this->entityManager->persist($sibling);
        $this->entityManager->flush();

        return [$user, $target, $sibling];
    }

    private function change(
        ?bool $isHidden = null,
        ?bool $isFavorite = null,
        ?bool $isKept = null,
        ?bool $isViewed = null,
        ?bool $isShared = null,
    ): EntryStateChangeModel {
        return new EntryStateChangeModel($isHidden, $isFavorite, $isKept, $isViewed, $isShared);
    }

    /** The persisted state, or an unpersisted all-false default when no row was ever created for it. */
    private function findStateOrDefault(User $user, Entry $entry): EntryState
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($user->requireId(), $entry->requireId())
            ?? new EntryState($user, $entry);
    }

    private function findSharedAt(User $user, Entry $entry): ?\DateTimeImmutable
    {
        return $this->entityManager->getRepository(EntryShare::class)
            ->findSharedAt($user->requireId(), $entry->requireId());
    }

    /**
     * Null when no row was ever created. findStateOrDefault() cannot tell that apart from a row created but never
     * written to: its unpersisted fallback reads all-false too.
     */
    private function findPersistedState(User $user, Entry $entry): ?EntryState
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($user->requireId(), $entry->requireId());
    }

    private function rows(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
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
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $this->updater()->apply($user, $row, $this->change(isHidden: true));

        self::assertTrue($this->findStateOrDefault($user, $target)->isHidden());
        self::assertTrue($this->findStateOrDefault($user, $sibling)->isHidden());
    }

    public function testFavoriteDoesNotMirror(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $this->updater()->apply($user, $row, $this->change(isFavorite: true));

        self::assertTrue($this->findStateOrDefault($user, $target)->isFavorite());
        self::assertFalse($this->findStateOrDefault($user, $sibling)->isFavorite());
        self::assertNull($this->findPersistedState($user, $sibling));
    }

    public function testKeptDoesNotMirror(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $this->updater()->apply($user, $row, $this->change(isKept: true));

        self::assertTrue($this->findStateOrDefault($user, $target)->isKept());
        self::assertFalse($this->findStateOrDefault($user, $sibling)->isKept());
        self::assertNull($this->findPersistedState($user, $sibling));
    }

    public function testSharingOneCopyDoesNotMarkTheSibling(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $this->updater()->apply($user, $row, $this->change(isShared: true));

        self::assertNotNull($this->findSharedAt($user, $target));
        self::assertNull($this->findSharedAt($user, $sibling));
    }

    public function testSharingAgainMovesTheShareOnToTheNewInstant(): void
    {
        [$user, $target] = $this->seedGroup();
        $earlier = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->entityManager->getRepository(EntryShare::class)
            ->record($user->requireId(), $target->requireId(), $earlier);
        $sharedRow = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $updated = $this->updater()->apply($user, $sharedRow, $this->change(isShared: true));

        self::assertNotNull($updated->sharedAt);
        self::assertGreaterThan($earlier, $updated->sharedAt);
    }

    public function testSharingWhileSharesAreNotRecordedKeepsTheEarlierShare(): void
    {
        [$user, $target] = $this->seedGroup();
        $this->updater()->apply(
            $user,
            $this->rows()->getRowForUser($user->requireId(), $target->requireId()),
            $this->change(isShared: true),
        );
        StoredUserSettings::sharing($this->entityManager, $user)->setRememberingShares(false);
        $sharedRow = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $updated = $this->updater()->apply($user, $sharedRow, $this->change(isShared: true));

        self::assertEquals($this->findSharedAt($user, $target), $updated->sharedAt);
        self::assertNotNull($updated->sharedAt);
    }

    public function testUnfavouritingAndUnkeepingClearBothFlags(): void
    {
        [$user, $target] = $this->seedGroup();
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());
        $this->updater()->apply($user, $row, $this->change(isFavorite: true, isKept: true));

        $this->updater()->apply($user, $row, $this->change(isFavorite: false, isKept: false));

        self::assertFalse($this->findStateOrDefault($user, $target)->isFavorite());
        self::assertFalse($this->findStateOrDefault($user, $target)->isKept());
    }

    public function testViewedMirrorsToTheSubscribedSiblingAndImpliesHiddenThere(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $row = $this->rows()->getRowForUser($user->requireId(), $target->requireId());

        $this->updater()->apply($user, $row, $this->change(isViewed: true));

        self::assertTrue($this->findStateOrDefault($user, $target)->isViewed());
        self::assertTrue($this->findStateOrDefault($user, $sibling)->isViewed());
        // A viewed row is always hidden, on the mirrored copy too.
        self::assertTrue($this->findStateOrDefault($user, $sibling)->isHidden());
    }

    public function testReadReachesOnlyTheEntriesAtTheSameAddress(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $effectiveDate = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $stranger = new Entry(
            $sibling->getFeed(),
            'mirror-stranger',
            'https://news.example.com/other',
            'Other Article',
            $effectiveDate,
            $effectiveDate,
            'other-url-hash',
        );
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        $this->applyChange($user, $target, $this->change(isHidden: true));

        self::assertTrue($this->findStateOrDefault($user, $sibling)->isHidden());
        self::assertNull($this->findPersistedState($user, $stranger));
    }

    public function testReadOfAnEntryWithoutItemOrAddressReachesNoOtherEntry(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $effectiveDate = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $lone = new Entry($target->getFeed(), 'mirror-lone', null, 'Lone', $effectiveDate, $effectiveDate);
        $this->entityManager->persist($lone);
        $this->entityManager->flush();

        $this->applyChange($user, $lone, $this->change(isHidden: true));

        self::assertTrue($this->findStateOrDefault($user, $lone)->isHidden());
        self::assertNull($this->findPersistedState($user, $sibling));
    }

    public function testToggleReadMarksTheCopy(): void
    {
        $user = $this->persistReader();
        $copies = LemmyPostCopies::persistPair($this->entityManager, $user);

        $this->applyChange($user, $copies->localCopy, $this->change(isHidden: true));

        self::assertTrue($this->findStateOrDefault($user, $copies->allCopy)->isHidden());
    }

    public function testToggleUnreadUnmarksTheCopy(): void
    {
        $user = $this->persistReader();
        $copies = LemmyPostCopies::persistPair($this->entityManager, $user);
        $this->applyChange($user, $copies->localCopy, $this->change(isHidden: true));

        $this->applyChange($user, $copies->localCopy, $this->change(isHidden: false));

        self::assertFalse($this->findStateOrDefault($user, $copies->localCopy)->isHidden());
        self::assertNotNull($this->findPersistedState($user, $copies->allCopy));
        self::assertFalse($this->findStateOrDefault($user, $copies->allCopy)->isHidden());
    }

    public function testArticleCopiesKeepUrlHashMirror(): void
    {
        [$user, $target, $sibling] = $this->seedGroup();
        $this->applyChange($user, $target, $this->change(isHidden: true));
        self::assertTrue($this->findStateOrDefault($user, $sibling)->isHidden());

        $this->applyChange($user, $target, $this->change(isHidden: false));

        self::assertFalse($this->findStateOrDefault($user, $sibling)->isHidden());
    }

    public function testALinkedCopyReachesOnlyItsItemNotItsAddress(): void
    {
        $user = $this->persistReader();
        $copies = LemmyPostCopies::persistPair($this->entityManager, $user);
        $published = new \DateTimeImmutable('2026-11-14T00:00:00Z');
        $linkedArticle = new Entry(
            $copies->local->getFeed(),
            'linked-article',
            'https://news.example.com/linked',
            'Linked',
            $published,
            $published,
            'linked-url-hash',
        );
        $item = $copies->localCopy->getCanonicalItem();
        self::assertNotNull($item);
        $linkedArticle->linkTo($item);
        $unlinkedArticle = new Entry(
            $copies->all->getFeed(),
            'unlinked-article',
            'https://news.example.com/linked',
            'Unlinked',
            $published,
            $published,
            'linked-url-hash',
        );
        $this->entityManager->persist($linkedArticle);
        $this->entityManager->persist($unlinkedArticle);
        $this->entityManager->flush();

        $this->applyChange($user, $linkedArticle, $this->change(isHidden: true));

        self::assertTrue($this->findStateOrDefault($user, $copies->localCopy)->isHidden());
        self::assertTrue($this->findStateOrDefault($user, $copies->allCopy)->isHidden());
        self::assertNull($this->findPersistedState($user, $unlinkedArticle));
    }

    private function persistReader(): User
    {
        $user = new User('copies@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function applyChange(User $user, Entry $entry, EntryStateChangeModel $change): void
    {
        $this->updater()->apply($user, $this->rows()->getRowForUser($user->requireId(), $entry->requireId()), $change);
    }
}

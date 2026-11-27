<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Repository\EntryLabelRepository;
use App\Repository\EntryShareRepository;
use App\Service\Library\SavedPosts\SavedStateMover;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryShareFixtures;
use App\Tests\Support\NamesLabels;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\StateMarks;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedStateMoverTest extends DbTestCase
{
    use NamesLabels;

    private const string READ_AT = '2026-10-16 08:00:00';
    private const string VIEWED_AT = '2026-10-16 08:05:00';

    private User $user;

    private Entry $source;

    private Entry $target;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('mover@example.test');
        $feed = new Feed('https://mover.example/feed.xml');
        $this->entityManager->persist($feed);
        $this->source = SavedPostFixtures::entry($this->entityManager, $feed, 'source', 'https://mover.example/1');
        $this->target = SavedPostFixtures::entry($this->entityManager, $feed, 'target', 'https://mover.example/2');
    }

    public function testTheTargetTakesTheFlagsAndTheSourceLosesThemWithoutTheCallerFlushing(): void
    {
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        StateMarks::favorite($state);
        StateMarks::kept($state);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        $target = $this->stateOf($targetId);
        self::assertTrue($target->isFavorite());
        self::assertTrue($target->isKept());
        $source = $this->stateOf($sourceId);
        self::assertFalse($source->isFavorite());
        self::assertFalse($source->isKept());
    }

    public function testALocalLikeMovesToTheSavedCopy(): void
    {
        $likedAt = new \DateTimeImmutable('2026-11-27T10:00:00Z');
        StateMarks::liked(SavedPostFixtures::state($this->entityManager, $this->user, $this->source), $likedAt);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($likedAt, $this->stateOf($targetId)->getLikedAt());
        self::assertFalse($this->stateOf($sourceId)->isLiked());
    }

    public function testTheEarlierLikeSurvivesWhenBothCopiesAreLiked(): void
    {
        $earlier = new \DateTimeImmutable('2026-11-20T10:00:00Z');
        $later = new \DateTimeImmutable('2026-11-27T10:00:00Z');
        StateMarks::liked(SavedPostFixtures::state($this->entityManager, $this->user, $this->source), $later);
        StateMarks::liked(SavedPostFixtures::state($this->entityManager, $this->user, $this->target), $earlier);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($earlier, $this->stateOf($targetId)->getLikedAt());
    }

    public function testASourceLikedEarlierReplacesALaterLikeOnTheTarget(): void
    {
        $earlier = new \DateTimeImmutable('2026-11-20T10:00:00Z');
        StateMarks::liked(SavedPostFixtures::state($this->entityManager, $this->user, $this->source), $earlier);
        StateMarks::liked(
            SavedPostFixtures::state($this->entityManager, $this->user, $this->target),
            new \DateTimeImmutable('2026-11-27T10:00:00Z'),
        );
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($earlier, $this->stateOf($targetId)->getLikedAt());
    }

    public function testTheTargetTakesTheSharedInstantAndTheSourceLosesIt(): void
    {
        $sharedAt = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        EntryShareFixtures::share($this->entityManager, $this->user, $this->source, $sharedAt);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($sharedAt, $this->sharedAtOf($targetId));
        self::assertNull($this->sharedAtOf($sourceId));
    }

    public function testATargetSharedLaterKeepsItsLaterShareInstant(): void
    {
        $laterShare = new \DateTimeImmutable('2026-10-09T10:00:00Z');
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $this->source,
            new \DateTimeImmutable('2026-10-02T10:00:00Z'),
        );
        EntryShareFixtures::share($this->entityManager, $this->user, $this->target, $laterShare);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($laterShare, $this->sharedAtOf($targetId));
        self::assertNull($this->sharedAtOf($sourceId));
    }

    public function testASourceSharedLaterMovesItsLaterShareInstantOntoTheTarget(): void
    {
        $laterShare = new \DateTimeImmutable('2026-10-09T10:00:00Z');
        EntryShareFixtures::share($this->entityManager, $this->user, $this->source, $laterShare);
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $this->target,
            new \DateTimeImmutable('2026-10-02T10:00:00Z'),
        );
        $this->entityManager->flush();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$this->source->requireId() => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($laterShare, $this->sharedAtOf($targetId));
    }

    public function testTwoSourcesOntoOneTargetLeaveTheLaterShareInstantAndNoSourceShare(): void
    {
        $feed = $this->source->getFeed();
        $otherSource = SavedPostFixtures::entry($this->entityManager, $feed, 'other', 'https://mover.example/3');
        $laterShare = new \DateTimeImmutable('2026-10-09T10:00:00Z');
        EntryShareFixtures::share($this->entityManager, $this->user, $this->source, $laterShare);
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $otherSource,
            new \DateTimeImmutable('2026-10-02T10:00:00Z'),
        );
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $otherSourceId = $otherSource->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId, $otherSourceId => $targetId]);
        $this->entityManager->clear();

        self::assertEquals($laterShare, $this->sharedAtOf($targetId));
        self::assertNull($this->sharedAtOf($sourceId));
        self::assertNull($this->sharedAtOf($otherSourceId));
    }

    public function testAHiddenSourceHidesTheNewTargetFromTheSameInstant(): void
    {
        $hiddenAt = new \DateTimeImmutable('2026-10-16 08:30:00');
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        StateMarks::favorite($state);
        $state->hide($hiddenAt);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        $target = $this->stateOf($targetId);
        self::assertTrue($target->isHidden());
        self::assertEquals($hiddenAt, $target->getHiddenAt());
    }

    public function testAnUnhiddenSourceLeavesTheNewTargetUnhidden(): void
    {
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        StateMarks::kept($state);
        $this->entityManager->flush();
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        $target = $this->stateOf($targetId);
        self::assertFalse($target->isHidden());
        self::assertNull($target->getHiddenAt());
    }

    public function testASourceWithoutAnyStateStillGetsATargetRowAndNothingMoves(): void
    {
        $sourceId = $this->source->requireId();
        $targetId = $this->target->requireId();

        $this->mover()->move($this->user->requireId(), [$sourceId => $targetId]);
        $this->entityManager->clear();

        $target = $this->stateOf($targetId);
        self::assertFalse($target->isFavorite());
        self::assertFalse($target->isKept());
        self::assertFalse($target->isHidden());
    }

    public function testTheReadMarkAndTheOpeningCarryOver(): void
    {
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        $state->hide(new \DateTimeImmutable(self::READ_AT));
        $state->markViewed(new \DateTimeImmutable(self::VIEWED_AT));
        $this->entityManager->flush();

        $this->moveSourceToTarget();

        $target = $this->stateOf($this->target->requireId());
        self::assertTrue($target->isHidden());
        self::assertSame(self::READ_AT, $target->getHiddenAt()?->format('Y-m-d H:i:s'));
        self::assertSame(self::VIEWED_AT, $target->getViewedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAReadMarkWithoutAnInstantStaysRead(): void
    {
        SavedPostFixtures::state($this->entityManager, $this->user, $this->source)
            ->restoreReadMark(new BackedUpReadMark(true, null));
        $this->entityManager->flush();

        $this->moveSourceToTarget();

        self::assertTrue($this->stateOf($this->target->requireId())->isHidden());
    }

    public function testATargetAlreadySavedStaysSaved(): void
    {
        SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        StateMarks::favorite(SavedPostFixtures::state($this->entityManager, $this->user, $this->target));
        $this->entityManager->flush();

        $this->moveSourceToTarget();

        self::assertTrue($this->stateOf($this->target->requireId())->isFavorite());
    }

    public function testMoveCarriesLabelsOntoTheSavedCopy(): void
    {
        $work = $this->labelNamed($this->user, 'Work');
        $readLater = $this->labelNamed($this->user, 'Read later');
        $this->entityManager->persist(new EntryLabel($work, $this->source));
        $this->entityManager->persist(new EntryLabel($work, $this->target));
        $this->entityManager->persist(new EntryLabel($readLater, $this->target));
        $this->entityManager->flush();

        $this->moveSourceToTarget();

        self::assertSame(['Read later', 'Work'], $this->labelNamesOn($this->user, $this->target->requireId()));
        self::assertSame([], $this->labelNamesOn($this->user, $this->source->requireId()));
    }

    public function testTwoSourcesOntoOneTargetLeaveEachLabelOnceOnTheTarget(): void
    {
        $work = $this->labelNamed($this->user, 'Work');
        $otherSource = SavedPostFixtures::entry(
            $this->entityManager,
            $this->source->getFeed(),
            'other-source',
            'https://mover.example/3',
        );
        $this->entityManager->persist(new EntryLabel($work, $this->source));
        $this->entityManager->persist(new EntryLabel($work, $otherSource));
        $this->entityManager->flush();
        $targetId = $this->target->requireId();

        $this->mover()->move(
            $this->user->requireId(),
            [$this->source->requireId() => $targetId, $otherSource->requireId() => $targetId],
        );
        $this->entityManager->clear();

        self::assertSame(['Work'], $this->labelNamesOn($this->user, $targetId));
    }

    public function testAnotherUsersLabelOnTheSourceStaysWhereItIs(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->entityManager, $hasher))->create('stranger@example.test');
        $this->entityManager->persist(new EntryLabel($this->labelNamed($stranger, 'Theirs'), $this->source));
        $this->entityManager->persist(new EntryLabel($this->labelNamed($this->user, 'Mine'), $this->source));
        $this->entityManager->flush();

        $this->moveSourceToTarget();

        self::assertSame(['Theirs'], $this->labelNamesOn($stranger, $this->source->requireId()));
        self::assertSame([], $this->labelNamesOn($stranger, $this->target->requireId()));
        self::assertSame(['Mine'], $this->labelNamesOn($this->user, $this->target->requireId()));
    }

    private function moveSourceToTarget(): void
    {
        $this->mover()->move($this->user->requireId(), [$this->source->requireId() => $this->target->requireId()]);
        $this->entityManager->clear();
    }

    private function stateOf(int $entryId): EntryState
    {
        $state = $this->entityManager->getRepository(EntryState::class)->findOneBy([
            'user' => $this->user->requireId(),
            'entry' => $entryId,
        ]);
        self::assertInstanceOf(EntryState::class, $state);

        return $state;
    }

    private function sharedAtOf(int $entryId): ?\DateTimeImmutable
    {
        /** @var EntryShareRepository $shares */
        $shares = $this->entityManager->getRepository(EntryShare::class);

        return $shares->findSharedAt($this->user->requireId(), $entryId);
    }

    /** @return list<string> */
    private function labelNamesOn(User $user, int $entryId): array
    {
        /** @var EntryLabelRepository $entryLabels */
        $entryLabels = $this->entityManager->getRepository(EntryLabel::class);

        return array_column($entryLabels->findLabelsByEntryIds($user->requireId(), [$entryId])[$entryId] ?? [], 'name');
    }

    private function mover(): SavedStateMover
    {
        /** @var SavedStateMover $mover */
        $mover = self::getContainer()->get(SavedStateMover::class);

        return $mover;
    }
}

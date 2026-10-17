<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\SavedPosts\SavedStateMover;
use App\Tests\DbTestCase;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedStateMoverTest extends DbTestCase
{
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
        $state->markFavorite();
        $state->markKept();
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

    public function testAHiddenSourceHidesTheNewTargetFromTheSameInstant(): void
    {
        $hiddenAt = new \DateTimeImmutable('2026-10-16 08:30:00');
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $this->source);
        $state->markFavorite();
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
        $state->markKept();
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

    private function stateOf(int $entryId): EntryState
    {
        $state = $this->entityManager->getRepository(EntryState::class)->findOneBy([
            'user' => $this->user->requireId(),
            'entry' => $entryId,
        ]);
        self::assertInstanceOf(EntryState::class, $state);

        return $state;
    }

    private function mover(): SavedStateMover
    {
        /** @var SavedStateMover $mover */
        $mover = self::getContainer()->get(SavedStateMover::class);

        return $mover;
    }
}

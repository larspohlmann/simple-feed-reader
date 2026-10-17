<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Repository\SocialLikeRepository;
use App\Service\SavedPosts\SavedStateMover;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedStateMoverTest extends DbTestCase
{
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
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-state-mover@example.test');
        $this->source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $this->target = $this->entry('social:bluesky:1:author:did:plc:rusty');
    }

    public function testFavouriteAndKeptMoveToTheTarget(): void
    {
        $state = $this->stateOf($this->source);
        $state->markFavorite();
        $state->markKept();
        $this->entityManager->flush();

        $this->move();

        $target = $this->storedState($this->target);
        self::assertTrue($target?->isFavorite());
        self::assertTrue($target->isKept());
        $source = $this->storedState($this->source);
        self::assertFalse($source?->isFavorite());
        self::assertFalse($source->isKept());
    }

    public function testTheReadMarkAndTheOpeningCarryOver(): void
    {
        $state = $this->stateOf($this->source);
        $state->hide(new \DateTimeImmutable(self::READ_AT));
        $state->markViewed(new \DateTimeImmutable(self::VIEWED_AT));
        $this->entityManager->flush();

        $this->move();

        $target = $this->storedState($this->target);
        self::assertTrue($target?->isHidden());
        self::assertSame(self::READ_AT, $target->getHiddenAt()?->format('Y-m-d H:i:s'));
        self::assertSame(self::VIEWED_AT, $target->getViewedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAReadMarkWithoutAnInstantStaysRead(): void
    {
        $this->stateOf($this->source)->restoreReadMark(new BackedUpReadMark(true, null));
        $this->entityManager->flush();

        $this->move();

        self::assertTrue($this->storedState($this->target)?->isHidden());
    }

    public function testATargetAlreadySavedStaysSaved(): void
    {
        $this->stateOf($this->source);
        $this->stateOf($this->target)->markFavorite();
        $this->entityManager->flush();

        $this->move();

        self::assertTrue($this->storedState($this->target)?->isFavorite());
    }

    public function testTheLikeIsCopied(): void
    {
        $this->stateOf($this->source);
        $account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($account);
        $this->entityManager->persist(
            new SocialLike($this->user, $this->source, $account, 'at://like', new \DateTimeImmutable(self::READ_AT)),
        );
        $this->entityManager->flush();

        $this->move();

        self::assertSame('at://like', $this->likes()->findOneFor($this->user, $this->target)?->getRemoteId());
    }

    public function testAPostWithoutStateLeavesTheTargetWithoutARow(): void
    {
        $this->move();

        self::assertNull($this->storedState($this->target));
    }

    private function move(): void
    {
        (new SavedStateMover($this->states(), $this->likes(), $this->entityManager))
            ->move($this->user->requireId(), [$this->source->requireId() => $this->target->requireId()]);
        $this->entityManager->clear();
    }

    private function stateOf(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    private function storedState(Entry $entry): ?EntryState
    {
        return $this->states()->findOneForUserEntry($this->user->requireId(), $entry->requireId());
    }

    private function states(): EntryStateRepository
    {
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $states);

        return $states;
    }

    private function likes(): SocialLikeRepository
    {
        $likes = $this->entityManager->getRepository(SocialLike::class);
        self::assertInstanceOf(SocialLikeRepository::class, $likes);

        return $likes;
    }

    private function entry(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $at = new \DateTimeImmutable('2026-10-16 07:00:00');
        $entry = new Entry($feed, 'at://did:plc:rusty/app.bsky.feed.post/3abc', null, 'Post', $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}

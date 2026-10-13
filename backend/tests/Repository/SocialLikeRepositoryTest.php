<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SocialLikeRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private SocialAccount $account;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('social-likes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->feed = new Feed('https://mastodon.social/@Gargron.rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testALikeIsFoundForItsOwnUserAndEntryOnly(): void
    {
        $liked = $this->entry('liked');
        $unliked = $this->entry('unliked');
        $like = $this->like($this->owner, $this->account, $liked);

        self::assertSame($like, $this->repository()->findOneFor($this->owner, $liked));
        self::assertNull($this->repository()->findOneFor($this->owner, $unliked));
        self::assertNull($this->repository()->findOneFor($this->user('likes-stranger@example.test'), $liked));
    }

    public function testLikedIdsAreTheUsersOwnAmongTheAskedEntries(): void
    {
        $first = $this->entry('first');
        $second = $this->entry('second');
        $unasked = $this->entry('unasked');
        $strangersOnly = $this->entry('strangers-only');
        $this->like($this->owner, $this->account, $first);
        $this->like($this->owner, $this->account, $second);
        $this->like($this->owner, $this->account, $unasked);
        $stranger = $this->user('likes-ids-stranger@example.test');
        $this->like(
            $stranger,
            SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger)),
            $strangersOnly,
        );

        $likedIds = $this->repository()->likedEntryIdsAmong(
            $this->owner->requireId(),
            [$first->requireId(), $second->requireId(), $strangersOnly->requireId()],
        );
        sort($likedIds);

        self::assertSame([$first->requireId(), $second->requireId()], $likedIds);
        self::assertSame([], $this->repository()->likedEntryIdsAmong($this->owner->requireId(), []));
    }

    public function testASecondLikeOfTheSameEntryIsRefusedByTheDatabase(): void
    {
        $entry = $this->entry('twice');
        $this->like($this->owner, $this->account, $entry);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->like($this->owner, $this->account, $entry);
    }

    public function testRemovingTheEntryDropsItsLikeOnly(): void
    {
        $byEntry = $this->entry('by-entry');
        $kept = $this->entry('kept');
        $this->like($this->owner, $this->account, $byEntry);
        $this->like($this->owner, $this->account, $kept);

        $this->entityManager->remove($byEntry);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(1, $this->repository()->count([]));
    }

    public function testRemovingTheAccountDropsItsLikes(): void
    {
        $this->like($this->owner, $this->account, $this->entry('by-account'));
        $this->entityManager->clear();

        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        self::assertNotNull($account);
        $this->entityManager->remove($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->repository()->count([]));
    }

    public function testRemovingTheUserDropsTheirLikes(): void
    {
        $this->like($this->owner, $this->account, $this->entry('by-user'));
        $this->entityManager->clear();

        $owner = $this->entityManager->find(User::class, $this->owner->requireId());
        self::assertNotNull($owner);
        $this->entityManager->remove($owner);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->repository()->count([]));
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');
        $entry = new Entry($this->feed, $guid, 'https://mastodon.social/@Gargron/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function like(User $user, SocialAccount $account, Entry $entry): SocialLike
    {
        $like = new SocialLike($user, $entry, $account, '110', new \DateTimeImmutable('2026-10-13 10:05:00'));
        $this->entityManager->persist($like);
        $this->entityManager->flush();

        return $like;
    }

    private function repository(): SocialLikeRepository
    {
        /** @var SocialLikeRepository $repository */
        $repository = $this->entityManager->getRepository(SocialLike::class);

        return $repository;
    }
}

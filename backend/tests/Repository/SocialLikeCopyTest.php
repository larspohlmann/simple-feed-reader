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

final class SocialLikeCopyTest extends DbTestCase
{
    use SeedsUsers;

    private const string LIKE = 'at://did:plc:alice/app.bsky.feed.like/3l1';

    private User $user;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user('social-like-copy@example.test');
        $this->account = SocialAccounts::blueskyOf($this->user);
        $this->entityManager->persist($this->account);
    }

    public function testTheLikeIsGivenToTheSamePostInTheOtherFeed(): void
    {
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $this->like($source, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());

        $copied = $this->likes()->findOneFor($this->user, $target);
        self::assertSame(self::LIKE, $copied?->getRemoteId());
        self::assertSame('2026-10-16 08:00:00', $copied->getLikedAt()->format('Y-m-d H:i:s'));
    }

    public function testALikedTargetKeepsItsOwnAndAnUnlikedSourceCopiesNothing(): void
    {
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $unliked = $this->entry('https://bsky.app/profile/did:plc:other/rss');
        $this->like($target, 'at://own', '2026-10-16 09:00:00');
        $this->like($source, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());
        $this->likes()->copyLike($this->user->requireId(), $unliked->requireId(), $target->requireId());

        self::assertSame('at://own', $this->likes()->findOneFor($this->user, $target)?->getRemoteId());
        self::assertSame(2, $this->likes()->count([]));
    }

    public function testAnUnlikedSourceGivesTheTargetNoLikeOfAnotherPost(): void
    {
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $unrelated = $this->entry('https://bsky.app/profile/did:plc:other/rss');
        $this->like($unrelated, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());

        self::assertNull($this->likes()->findOneFor($this->user, $target));
    }

    public function testOnlyTheUsersOwnLikeIsCopied(): void
    {
        $other = $this->user('social-like-copy-other@example.test');
        $otherAccount = SocialAccounts::blueskyOf($other);
        $this->entityManager->persist($otherAccount);
        $source = $this->entry('https://bsky.app/profile/did:plc:rusty/rss');
        $target = $this->entry('social:bluesky:1:author:did:plc:rusty');
        $this->entityManager->persist(new SocialLike(
            $other,
            $source,
            $otherAccount,
            'at://other-like',
            new \DateTimeImmutable('2026-10-16 07:30:00'),
        ));
        $this->like($source, self::LIKE, '2026-10-16 08:00:00');

        $this->likes()->copyLike($this->user->requireId(), $source->requireId(), $target->requireId());

        self::assertSame(self::LIKE, $this->likes()->findOneFor($this->user, $target)?->getRemoteId());
        self::assertNull($this->likes()->findOneFor($other, $target));
    }

    private function like(Entry $entry, string $remoteId, string $likedAt): void
    {
        $this->entityManager->persist(
            new SocialLike($this->user, $entry, $this->account, $remoteId, new \DateTimeImmutable($likedAt)),
        );
        $this->entityManager->flush();
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

    private function likes(): SocialLikeRepository
    {
        $likes = $this->entityManager->getRepository(SocialLike::class);
        self::assertInstanceOf(SocialLikeRepository::class, $likes);

        return $likes;
    }
}

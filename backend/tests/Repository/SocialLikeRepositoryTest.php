<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Repository\SocialLikeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class SocialLikeRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('social-likes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testALikeIsFoundForItsOwnUserAndItemOnly(): void
    {
        $liked = $this->persistItem('liked');
        $unliked = $this->persistItem('unliked');
        $like = $this->persistLike($this->owner, $this->account, $liked);

        self::assertSame($like, $this->repository()->findOneFor($this->owner, $liked));
        self::assertNull($this->repository()->findOneFor($this->owner, $unliked));
        self::assertNull($this->repository()->findOneFor($this->user('likes-stranger@example.test'), $liked));
    }

    public function testLikedIdsAreTheUsersOwnAmongTheAskedItems(): void
    {
        $first = $this->persistItem('first');
        $second = $this->persistItem('second');
        $unasked = $this->persistItem('unasked');
        $strangersOnly = $this->persistItem('strangers-only');
        $this->persistLike($this->owner, $this->account, $first);
        $this->persistLike($this->owner, $this->account, $second);
        $this->persistLike($this->owner, $this->account, $unasked);
        $stranger = $this->user('likes-ids-stranger@example.test');
        $this->persistLike(
            $stranger,
            SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger)),
            $strangersOnly,
        );

        $likedIds = $this->repository()->findLikedItemIds(
            $this->owner->requireId(),
            [$first->requireId(), $second->requireId(), $strangersOnly->requireId()],
        );
        ksort($likedIds);

        self::assertSame([$first->requireId() => true, $second->requireId() => true], $likedIds);
        self::assertSame([], $this->repository()->findLikedItemIds($this->owner->requireId(), []));
    }

    public function testASecondLikeOfTheSameItemIsRefusedByTheDatabase(): void
    {
        $item = $this->persistItem('twice');
        $this->persistLike($this->owner, $this->account, $item);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->persistLike($this->owner, $this->account, $item);
    }

    public function testRemovingTheItemDropsItsLikeOnly(): void
    {
        $byItem = $this->persistItem('by-item');
        $kept = $this->persistItem('kept');
        $this->persistLike($this->owner, $this->account, $byItem);
        $this->persistLike($this->owner, $this->account, $kept);

        $this->entityManager->remove($byItem);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(1, $this->repository()->count([]));
    }

    public function testRemovingTheAccountDropsItsLikes(): void
    {
        $this->persistLike($this->owner, $this->account, $this->persistItem('by-account'));
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
        $this->persistLike($this->owner, $this->account, $this->persistItem('by-user'));
        $this->entityManager->clear();

        $owner = $this->entityManager->find(User::class, $this->owner->requireId());
        self::assertNotNull($owner);
        $this->entityManager->remove($owner);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->repository()->count([]));
    }

    private function persistItem(string $statusId): CanonicalItem
    {
        $statusUri = 'https://mastodon.social/users/Gargron/statuses/' . $statusId;
        $item = CanonicalItems::buildItem(CanonicalItemKind::Mastodon, $statusUri);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    private function persistLike(User $user, SocialAccount $account, CanonicalItem $item): SocialLike
    {
        $like = new SocialLike($user, $item, $account, '110', new \DateTimeImmutable('2026-10-13 10:05:00'));
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

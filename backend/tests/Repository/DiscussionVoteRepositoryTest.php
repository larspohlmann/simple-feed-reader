<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Exception\NoVoteToStoreException;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DiscussionVoteRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('discussion-votes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testScoresAmongReadsOnlyThisUsersVotes(): void
    {
        $item = $this->persistItem('shared');
        $stranger = $this->user('discussion-votes-stranger@example.test');
        $strangerAccount = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($stranger),
        );
        $this->vote($this->owner, $this->account, $item, VoteScore::Up);
        $this->vote($stranger, $strangerAccount, $item, VoteScore::Down);
        $this->vote($this->owner, $this->account, $this->persistItem('unasked'), VoteScore::Down);

        $scores = $this->repository()->findScores($this->owner->requireId(), [$item->requireId()]);

        self::assertSame([$item->requireId() => VoteScore::Up], $scores);
    }

    public function testScoresAmongAnEmptyListAsksNothing(): void
    {
        self::assertSame([], $this->repository()->findScores($this->owner->requireId(), []));
    }

    public function testAVoteIsFoundForItsOwnUserAndItemOnly(): void
    {
        $voted = $this->persistItem('voted');
        $vote = $this->vote($this->owner, $this->account, $voted, VoteScore::Down);
        $stranger = $this->user('discussion-votes-finder@example.test');
        $this->vote(
            $stranger,
            SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger)),
            $voted,
            VoteScore::Up,
        );

        self::assertSame($vote, $this->repository()->findOneFor($this->owner, $voted));
        self::assertNull($this->repository()->findOneFor($this->owner, $this->persistItem('unvoted')));
    }

    public function testRecastingPersistsTheNewScore(): void
    {
        $item = $this->persistItem('recast');
        $vote = $this->vote($this->owner, $this->account, $item, VoteScore::Up);

        $vote->recast(VoteScore::Down, new \DateTimeImmutable('2026-11-14 11:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->repository()->find($vote->requireId());
        self::assertNotNull($reloaded);
        self::assertSame(VoteScore::Down, $reloaded->getScore());
    }

    public function testAVoteOfNoneIsRefusedAtConstruction(): void
    {
        $this->expectException(NoVoteToStoreException::class);

        $this->vote($this->owner, $this->account, $this->persistItem('none'), VoteScore::None);
    }

    public function testRecastingToNoneIsRefused(): void
    {
        $vote = $this->vote($this->owner, $this->account, $this->persistItem('recast-none'), VoteScore::Up);

        $this->expectException(NoVoteToStoreException::class);

        $vote->recast(VoteScore::None, new \DateTimeImmutable('2026-11-14 11:00:00'));
    }

    public function testOneVotePerUserAndItem(): void
    {
        $item = $this->persistItem('twice');
        $this->vote($this->owner, $this->account, $item, VoteScore::Up);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->vote($this->owner, $this->account, $item, VoteScore::Down);
    }

    public function testStoringAFirstVoteInsertsIt(): void
    {
        $item = $this->persistItem('stored');
        $voteAt = new \DateTimeImmutable('2026-11-14 11:00:00');

        $this->repository()->store(new DiscussionVote($this->owner, $item, $this->account, VoteScore::Down, $voteAt));
        $this->entityManager->clear();

        $stored = $this->repository()->findOneFor($this->owner, $item);
        self::assertNotNull($stored);
        self::assertSame(VoteScore::Down, $stored->getScore());
        self::assertEquals($voteAt, $stored->getVotedAt());
    }

    public function testStoringOverAVoteACompetingRequestInsertedRecastsIt(): void
    {
        $item = $this->persistItem('raced');
        $this->vote($this->owner, $this->account, $item, VoteScore::Up);
        $this->entityManager->clear();
        $owner = $this->entityManager->find(User::class, $this->owner->requireId());
        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        $reloadedItem = $this->entityManager->find(CanonicalItem::class, $item->requireId());
        self::assertNotNull($owner);
        self::assertNotNull($account);
        self::assertNotNull($reloadedItem);
        $this->entityManager->clear();

        $votedAt = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->repository()->store(new DiscussionVote($owner, $reloadedItem, $account, VoteScore::Down, $votedAt));
        $this->entityManager->clear();

        self::assertSame(1, $this->repository()->count([]));
        $stored = $this->repository()->findOneFor($owner, $reloadedItem);
        self::assertSame(VoteScore::Down, $stored?->getScore());
    }

    public function testStoringOverAVoteACompetingRequestInsertedKeepsItsAccount(): void
    {
        $item = $this->persistItem('raced-account');
        $this->vote($this->owner, $this->account, $item, VoteScore::Up);
        $other = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'alice2@mastodon.example'),
        );
        $votedAt = new \DateTimeImmutable('2026-11-14 12:00:00');

        $this->repository()->store(new DiscussionVote($this->owner, $item, $other, VoteScore::Down, $votedAt));
        $this->entityManager->clear();

        $stored = $this->repository()->findOneFor($this->owner, $item);
        self::assertSame(VoteScore::Down, $stored?->getScore());
        self::assertSame($this->account->requireId(), $stored->getSocialAccount()->requireId());
    }

    public function testDeletingTheAccountDropsItsVotes(): void
    {
        $this->vote($this->owner, $this->account, $this->persistItem('by-account'), VoteScore::Up);
        $this->entityManager->clear();

        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        self::assertNotNull($account);
        $this->entityManager->remove($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->repository()->count([]));
    }

    private function persistItem(string $postId): CanonicalItem
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Lemmy, 'https://lemmy.example/post/' . $postId);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    private function vote(User $user, SocialAccount $account, CanonicalItem $item, VoteScore $score): DiscussionVote
    {
        $vote = new DiscussionVote($user, $item, $account, $score, new \DateTimeImmutable('2026-11-14 10:05:00'));
        $this->entityManager->persist($vote);
        $this->entityManager->flush();

        return $vote;
    }

    private function repository(): DiscussionVoteRepository
    {
        /** @var DiscussionVoteRepository $repository */
        $repository = $this->entityManager->getRepository(DiscussionVote::class);

        return $repository;
    }
}

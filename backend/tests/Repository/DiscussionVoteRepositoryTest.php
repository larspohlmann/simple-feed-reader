<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\Exception\NoVoteToStoreException;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DiscussionVoteRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private SocialAccount $account;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('discussion-votes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $this->feed = new Feed('https://lemmy.example/feeds/c/rust.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testScoresAmongReadsOnlyThisUsersVotes(): void
    {
        $entry = $this->entry('shared');
        $stranger = $this->user('discussion-votes-stranger@example.test');
        $strangerAccount = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($stranger),
        );
        $this->vote($this->owner, $this->account, $entry, VoteScore::Up);
        $this->vote($stranger, $strangerAccount, $entry, VoteScore::Down);
        $this->vote($this->owner, $this->account, $this->entry('unasked'), VoteScore::Down);

        $scores = $this->repository()->scoresAmong($this->owner->requireId(), [$entry->requireId()]);

        self::assertSame([$entry->requireId() => VoteScore::Up], $scores);
    }

    public function testScoresAmongAnEmptyListAsksNothing(): void
    {
        self::assertSame([], $this->repository()->scoresAmong($this->owner->requireId(), []));
    }

    public function testAVoteIsFoundForItsOwnUserAndEntryOnly(): void
    {
        $voted = $this->entry('voted');
        $vote = $this->vote($this->owner, $this->account, $voted, VoteScore::Down);
        $stranger = $this->user('discussion-votes-finder@example.test');
        $this->vote(
            $stranger,
            SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger)),
            $voted,
            VoteScore::Up,
        );

        self::assertSame($vote, $this->repository()->findOneFor($this->owner, $voted));
        self::assertNull($this->repository()->findOneFor($this->owner, $this->entry('unvoted')));
    }

    public function testRecastingPersistsTheNewScore(): void
    {
        $entry = $this->entry('recast');
        $vote = $this->vote($this->owner, $this->account, $entry, VoteScore::Up);

        $vote->recast($this->account, VoteScore::Down, new \DateTimeImmutable('2026-11-14 11:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->repository()->find($vote->requireId());
        self::assertNotNull($reloaded);
        self::assertSame(VoteScore::Down, $reloaded->getScore());
    }

    public function testAVoteOfNoneIsRefusedAtConstruction(): void
    {
        $this->expectException(NoVoteToStoreException::class);

        $this->vote($this->owner, $this->account, $this->entry('none'), VoteScore::None);
    }

    public function testRecastingToNoneIsRefused(): void
    {
        $vote = $this->vote($this->owner, $this->account, $this->entry('recast-none'), VoteScore::Up);

        $this->expectException(NoVoteToStoreException::class);

        $vote->recast($this->account, VoteScore::None, new \DateTimeImmutable('2026-11-14 11:00:00'));
    }

    public function testOneVotePerUserAndEntry(): void
    {
        $entry = $this->entry('twice');
        $this->vote($this->owner, $this->account, $entry, VoteScore::Up);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->vote($this->owner, $this->account, $entry, VoteScore::Down);
    }

    public function testStoringAFirstVoteInsertsIt(): void
    {
        $entry = $this->entry('stored');
        $voteAt = new \DateTimeImmutable('2026-11-14 11:00:00');

        $this->repository()->store(new DiscussionVote($this->owner, $entry, $this->account, VoteScore::Down, $voteAt));
        $this->entityManager->clear();

        $stored = $this->repository()->findOneFor($this->owner, $entry);
        self::assertNotNull($stored);
        self::assertSame(VoteScore::Down, $stored->getScore());
        self::assertEquals($voteAt, $stored->getVotedAt());
    }

    public function testStoringOverAVoteACompetingRequestInsertedRecastsIt(): void
    {
        $entry = $this->entry('raced');
        $this->vote($this->owner, $this->account, $entry, VoteScore::Up);
        $this->entityManager->clear();
        $owner = $this->entityManager->find(User::class, $this->owner->requireId());
        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        $reloadedEntry = $this->entityManager->find(Entry::class, $entry->requireId());
        self::assertNotNull($owner);
        self::assertNotNull($account);
        self::assertNotNull($reloadedEntry);
        $this->entityManager->clear();

        $this->repository()->store(
            new DiscussionVote($owner, $reloadedEntry, $account, VoteScore::Down, new \DateTimeImmutable('2026-11-14 12:00:00')),
        );
        $this->entityManager->clear();

        self::assertSame(1, $this->repository()->count([]));
        $stored = $this->repository()->findOneFor($owner, $reloadedEntry);
        self::assertSame(VoteScore::Down, $stored?->getScore());
    }

    public function testDeletingTheAccountDropsItsVotes(): void
    {
        $this->vote($this->owner, $this->account, $this->entry('by-account'), VoteScore::Up);
        $this->entityManager->clear();

        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        self::assertNotNull($account);
        $this->entityManager->remove($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->repository()->count([]));
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-11-14 10:00:00');
        $entry = new Entry($this->feed, $guid, 'https://lemmy.example/post/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function vote(User $user, SocialAccount $account, Entry $entry, VoteScore $score): DiscussionVote
    {
        $vote = new DiscussionVote($user, $entry, $account, $score, new \DateTimeImmutable('2026-11-14 10:05:00'));
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

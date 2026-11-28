<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\InclusionSurface;
use App\Enum\VoteScore;
use App\Repository\ReadingHistoryRepository;
use App\Repository\TitledEntry;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryShareFixtures;
use App\Tests\Support\LikedPostFixtures;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\StateMarks;

/** The reader's history reads break a tie on the instant by the newest entry (#2007). */
final class ReadingHistoryRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('historian@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = new Feed('https://history.example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
    }

    public function testTwoSharesFromTheSameInstantComeBackHighestIdFirst(): void
    {
        $at = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        $older = SavedPostFixtures::entry($this->entityManager, $this->feed, 'a', 'https://history.example.com/a');
        $newer = SavedPostFixtures::entry($this->entityManager, $this->feed, 'b', 'https://history.example.com/b');
        EntryShareFixtures::share($this->entityManager, $this->user, $older, $at);
        EntryShareFixtures::share($this->entityManager, $this->user, $newer, $at);
        $this->entityManager->flush();

        $shared = $this->history()->shared($this->user->requireId(), 10);

        self::assertSame([$newer->requireId(), $older->requireId()], $this->entryIds($shared));
    }

    public function testWithASurfaceTheHistoryKeepsOnlyThatSurfacesFeeds(): void
    {
        $switchedOff = $this->persistSubscribedFeed($this->user, 'https://switched-off.example.com/feed.xml');
        $tagged = $this->persistSubscribedFeed($this->user, 'https://tagged.example.com/feed.xml');
        $inStream = $this->persistDatedEntry($this->feed, 'in-stream', '2026-10-01 10:00:00');
        $outOfStream = $this->persistDatedEntry($switchedOff, 'out-of-stream', '2026-10-02 10:00:00');
        $sharedUnderTag = $this->persistDatedEntry($tagged, 'shared-under-tag', '2026-10-03 10:00:00');
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $inStream));
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $outOfStream));
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $sharedUnderTag,
            new \DateTimeImmutable('2026-10-03 11:00:00'),
        );
        $this->subscriptionTo($switchedOff)->changeInclusions(new InclusionChange(yourStream: false));
        $tag = new Tag($this->user, 'Muted');
        $this->entityManager->persist($tag);
        $this->subscriptionTo($tagged)->addTag($tag);
        $tag->changeInclusions(new InclusionChange(yourStream: false));
        $this->entityManager->flush();
        $userId = $this->user->requireId();
        $scoped = $this->history()->withSurface(InclusionSurface::YourStream);

        self::assertSame([$inStream->requireId()], $this->entryIds($scoped->favorites($userId, 10)));
        self::assertSame([], $scoped->shared($userId, 10));
        self::assertSame(
            [$outOfStream->requireId(), $inStream->requireId()],
            $this->entryIds($this->history()->favorites($userId, 10)),
        );
        self::assertSame([$sharedUnderTag->requireId()], $this->entryIds($this->history()->shared($userId, 10)));
    }

    public function testWithASurfaceLikesKeepOnlyThatSurfacesFeeds(): void
    {
        $switchedOff = $this->persistSubscribedFeed($this->user, 'https://switched-off.example.com/feed.xml');
        $inStream = $this->persistEntry('liked-in-stream');
        $outOfStream = SavedPostFixtures::entry(
            $this->entityManager,
            $switchedOff,
            'liked-out',
            'https://switched-off.example.com/liked-out',
        );
        $this->likeEntry($inStream, '2026-10-02 10:00:00');
        $this->likeEntry($outOfStream, '2026-10-02 11:00:00');
        $this->likeLocally($this->persistEntry('local-in-stream'), '2026-10-02 12:00:00');
        $locallyOut = SavedPostFixtures::entry(
            $this->entityManager,
            $switchedOff,
            'local-out',
            'https://switched-off.example.com/local-out',
        );
        $this->likeLocally($locallyOut, '2026-10-02 13:00:00');
        $this->subscriptionTo($switchedOff)->changeInclusions(new InclusionChange(yourStream: false));
        $this->entityManager->flush();
        $userId = $this->user->requireId();

        $liked = $this->history()->withSurface(InclusionSurface::YourStream)->liked($userId, 10);

        self::assertCount(2, $liked);
        self::assertNotContains($outOfStream->requireId(), $this->entryIds($liked));
        self::assertNotContains($locallyOut->requireId(), $this->entryIds($liked));
        self::assertCount(4, $this->history()->liked($userId, 10));
    }

    public function testTwoViewsFromTheSameInstantComeBackHighestIdFirst(): void
    {
        $at = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        $older = SavedPostFixtures::entry($this->entityManager, $this->feed, 'a', 'https://history.example.com/a');
        $newer = SavedPostFixtures::entry($this->entityManager, $this->feed, 'b', 'https://history.example.com/b');
        SavedPostFixtures::state($this->entityManager, $this->user, $older)->markViewed($at);
        SavedPostFixtures::state($this->entityManager, $this->user, $newer)->markViewed($at);
        $this->entityManager->flush();

        $viewed = $this->history()->viewed($this->user->requireId(), 10);

        self::assertSame([$newer->requireId(), $older->requireId()], $this->entryIds($viewed));
    }

    public function testLikesAndUpvotesComeBackInOneCastOrder(): void
    {
        $first = $this->persistEntry('first');
        $upvoted = $this->persistEntry('upvoted');
        $third = $this->persistEntry('third');
        $this->likeEntry($first, '2026-10-02 10:00:00');
        $this->upvoteEntry($upvoted, '2026-10-02 11:00:00');
        $this->likeEntry($third, '2026-10-02 12:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame(
            [$third->requireId(), $upvoted->requireId(), $first->requireId()],
            $this->entryIds($liked),
        );
    }

    public function testTheLikedCapCutsTheMergedList(): void
    {
        $likedEarly = $this->persistEntry('liked-early');
        $upvotedEarly = $this->persistEntry('upvoted-early');
        $likedLate = $this->persistEntry('liked-late');
        $upvotedLate = $this->persistEntry('upvoted-late');
        $this->likeEntry($likedEarly, '2026-10-02 10:00:00');
        $this->upvoteEntry($upvotedEarly, '2026-10-02 11:00:00');
        $this->likeEntry($likedLate, '2026-10-02 12:00:00');
        $this->upvoteEntry($upvotedLate, '2026-10-02 13:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 3);

        self::assertSame(
            [$upvotedLate->requireId(), $likedLate->requireId(), $upvotedEarly->requireId()],
            $this->entryIds($liked),
        );
    }

    public function testTwoLikesFromTheSameInstantComeBackHighestIdFirst(): void
    {
        $older = $this->persistEntry('a');
        $newer = $this->persistEntry('b');
        $this->likeEntry($older, '2026-10-02 10:00:00');
        $this->likeEntry($newer, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$newer->requireId(), $older->requireId()], $this->entryIds($liked));
    }

    public function testADownvoteIsNotLiked(): void
    {
        $entry = $this->persistEntry('downvoted');
        StateMarks::markKept(SavedPostFixtures::state($this->entityManager, $this->user, $entry));
        LikedPostFixtures::persistVote(
            $this->entityManager,
            $this->user,
            $this->linkNewItem($entry, CanonicalItemKind::Lemmy),
            VoteScore::Down,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
        self::assertSame([$entry->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
    }

    public function testAnUpvoteRecastToADownvoteReturnsToKept(): void
    {
        $entry = $this->persistEntry('recast');
        StateMarks::markKept(SavedPostFixtures::state($this->entityManager, $this->user, $entry));
        $vote = $this->upvoteEntry($entry, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame([], $this->history()->kept($this->user->requireId(), 10));
        self::assertSame([$entry->requireId()], $this->entryIds($this->history()->liked($this->user->requireId(), 10)));

        $vote->recast(VoteScore::Down, new \DateTimeImmutable('2026-10-02 11:00:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
        self::assertSame([$entry->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
    }

    public function testALikedFavoriteIsNotInLiked(): void
    {
        $entry = $this->persistEntry('favorite');
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $entry));
        $this->likeEntry($entry, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$entry->requireId()],
            $this->entryIds($this->history()->favorites($this->user->requireId(), 10)),
        );
        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
    }

    public function testALikedSharedEntryIsNotInLiked(): void
    {
        $entry = $this->persistEntry('shared');
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $entry,
            new \DateTimeImmutable('2026-10-02 09:00:00'),
        );
        $this->likeEntry($entry, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$entry->requireId()],
            $this->entryIds($this->history()->shared($this->user->requireId(), 10)),
        );
        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
    }

    public function testALocallyLikedArticleIsLiked(): void
    {
        $network = $this->persistEntry('network');
        $local = $this->persistEntry('local');
        $older = $this->persistEntry('older');
        $this->likeEntry($network, '2026-10-02 10:00:00');
        $this->likeLocally($local, '2026-10-02 11:00:00');
        $this->likeLocally($older, '2026-10-02 09:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$local->requireId(), $network->requireId(), $older->requireId()], $this->entryIds($liked));
    }

    public function testAFavoriteOrSharedLocalLikeStaysOutOfLiked(): void
    {
        $favorite = $this->persistEntry('favorite');
        $shared = $this->persistEntry('shared');
        StateMarks::markFavorite($this->likeLocally($favorite, '2026-10-02 10:00:00'));
        $this->likeLocally($shared, '2026-10-02 10:00:00');
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $shared,
            new \DateTimeImmutable('2026-10-02 09:00:00'),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
    }

    public function testAnEntryLikedLocallyAndOnTheNetworkIsListedOnce(): void
    {
        $entry = $this->persistEntry('both');
        $this->likeLocally($entry, '2026-10-02 12:00:00');
        $this->likeEntry($entry, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$entry->requireId()], $this->entryIds($liked));
    }

    public function testALocalLikeOnOneOfTwoFollowedCopiesListsOnlyThatCopy(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->likeLocally($higher, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$higher->requireId()], $this->entryIds($liked));
        self::assertNotSame($lower->requireId(), $higher->requireId());
    }

    public function testANetworkLikedCopyAndALocallyLikedCopyGiveOneLine(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->likeItemOf($lower);
        $this->likeLocally($higher, '2026-10-02 12:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$higher->requireId()], $this->entryIds($liked));
    }

    public function testTwoLocallyLikedCopiesGiveOneLine(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->likeLocally($lower, '2026-10-02 10:00:00');
        $this->likeLocally($higher, '2026-10-02 11:00:00');
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertCount(1, $liked);
    }

    public function testALocalLikeInAFeedTheReaderLeftHasNoLine(): void
    {
        [, $left] = $this->persistPostInAFollowedAndALeftFeed();
        $this->likeLocally($left, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
    }

    public function testALocallyLikedEntryLeavesKeptAndViewed(): void
    {
        $kept = $this->persistEntry('kept');
        $viewed = $this->persistEntry('viewed');
        StateMarks::markKept($this->likeLocally($kept, '2026-10-02 10:00:00'));
        $viewedState = $this->markViewed($viewed, '2026-10-02 09:00:00');
        StateMarks::markLiked($viewedState, new \DateTimeImmutable('2026-10-02 11:00:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->history()->kept($this->user->requireId(), 10));
        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
    }

    public function testALikedEntryLeavesKeptAndViewed(): void
    {
        $kept = $this->persistEntry('kept');
        $viewed = $this->persistEntry('viewed');
        StateMarks::markKept(SavedPostFixtures::state($this->entityManager, $this->user, $kept));
        SavedPostFixtures::state($this->entityManager, $this->user, $viewed)
            ->markViewed(new \DateTimeImmutable('2026-10-02 09:00:00'));
        $this->likeEntry($kept, '2026-10-02 10:00:00');
        $this->upvoteEntry($viewed, '2026-10-02 11:00:00');
        $this->entityManager->flush();

        self::assertSame([], $this->history()->kept($this->user->requireId(), 10));
        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
    }

    public function testAnEntryWithoutAnItemStaysInKeptAndViewed(): void
    {
        $kept = $this->persistEntry('kept');
        $viewed = $this->persistEntry('viewed');
        StateMarks::markKept(SavedPostFixtures::state($this->entityManager, $this->user, $kept));
        SavedPostFixtures::state($this->entityManager, $this->user, $viewed)
            ->markViewed(new \DateTimeImmutable('2026-10-02 09:00:00'));
        $this->likeEntry($this->persistEntry('liked'), '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame([$kept->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
        self::assertSame(
            [$viewed->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testAStrangersLikeIsNotMine(): void
    {
        $stranger = $this->persistStranger();
        $entry = $this->persistEntry('kept');
        StateMarks::markKept(SavedPostFixtures::state($this->entityManager, $this->user, $entry));
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $stranger,
            $this->linkNewItem($entry, CanonicalItemKind::Mastodon),
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
        self::assertSame([$entry->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
    }

    public function testTwoCopiesOfALikedPostGiveOneLine(): void
    {
        $otherFeed = $this->persistSubscribedFeed($this->user, 'https://copies.example.com/feed.xml');
        $lower = $this->persistEntry('copy');
        $higher = SavedPostFixtures::entry($this->entityManager, $otherFeed, 'copy', 'https://copies.example.com/copy');
        $item = $this->linkNewItem($lower, CanonicalItemKind::Mastodon);
        $higher->linkTo($item);
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $this->user,
            $item,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$lower->requireId()], $this->entryIds($liked));
    }

    public function testALowerCopyInAFeedOnlyAStrangerFollowsDoesNotHideMine(): void
    {
        $strangersFeed = $this->persistSubscribedFeed(
            $this->persistStranger(),
            'https://stranger.example.com/feed.xml',
        );
        $lower = SavedPostFixtures::entry(
            $this->entityManager,
            $strangersFeed,
            'copy',
            'https://stranger.example.com/copy',
        );
        $higher = $this->persistEntry('copy');
        $item = $this->linkNewItem($lower, CanonicalItemKind::Mastodon);
        $higher->linkTo($item);
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $this->user,
            $item,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        $liked = $this->history()->liked($this->user->requireId(), 10);

        self::assertSame([$higher->requireId()], $this->entryIds($liked));
    }

    public function testALikedPostInAFeedTheReaderLeftHasNoLine(): void
    {
        $leftFeed = new Feed('https://left.example.com/feed.xml');
        $this->entityManager->persist($leftFeed);
        $entry = SavedPostFixtures::entry($this->entityManager, $leftFeed, 'left', 'https://left.example.com/left');
        $this->likeEntry($entry, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
    }

    public function testTwoViewedCopiesOfAPostGiveOneLine(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->markViewed($lower, '2026-10-02 09:00:00');
        $this->markViewed($higher, '2026-10-02 09:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$lower->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testAViewedLowerCopyInAFeedOnlyAStrangerFollowsDoesNotHideMine(): void
    {
        $strangersFeed = $this->persistSubscribedFeed(
            $this->persistStranger(),
            'https://stranger.example.com/feed.xml',
        );
        $lower = SavedPostFixtures::entry(
            $this->entityManager,
            $strangersFeed,
            'copy',
            'https://stranger.example.com/copy',
        );
        $higher = $this->persistEntry('copy');
        $higher->linkTo($this->linkNewItem($lower, CanonicalItemKind::Mastodon));
        $this->markViewed($lower, '2026-10-02 09:00:00');
        $this->markViewed($higher, '2026-10-02 09:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$higher->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testAViewedPostWithAFavoriteCopyIsOnlyInFavorites(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->markViewed($lower, '2026-10-02 09:00:00');
        StateMarks::markFavorite($this->markViewed($higher, '2026-10-02 09:00:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
        self::assertSame(
            [$higher->requireId()],
            $this->entryIds($this->history()->favorites($this->user->requireId(), 10)),
        );
    }

    public function testAViewedPostWithAKeptCopyIsOnlyInKept(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->markViewed($lower, '2026-10-02 09:00:00');
        StateMarks::markKept($this->markViewed($higher, '2026-10-02 09:00:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
        self::assertSame([$higher->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
    }

    public function testAViewedPostWithASharedCopyIsOnlyInShared(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        $this->markViewed($lower, '2026-10-02 09:00:00');
        $this->markViewed($higher, '2026-10-02 09:00:00');
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $higher,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
        self::assertSame(
            [$higher->requireId()],
            $this->entryIds($this->history()->shared($this->user->requireId(), 10)),
        );
    }

    public function testAViewedAndKeptLowerCopyKeepsThePostOutOfViewed(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        StateMarks::markKept($this->markViewed($lower, '2026-10-02 09:00:00'));
        $this->markViewed($higher, '2026-10-02 09:00:00');
        $this->entityManager->flush();

        self::assertSame([$lower->requireId()], $this->entryIds($this->history()->kept($this->user->requireId(), 10)));
        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
    }

    public function testAFavoriteCopyInAFeedTheReaderLeftDoesNotHideTheViewedCopy(): void
    {
        [$followed, $left] = $this->persistPostInAFollowedAndALeftFeed();
        $this->markViewed($followed, '2026-10-02 09:00:00');
        StateMarks::markFavorite($this->markViewed($left, '2026-10-02 09:00:00'));
        $this->entityManager->flush();

        self::assertSame(
            [$followed->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testAFavoriteLowerCopyKeepsThePostOutOfViewed(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $lower));
        $this->markViewed($higher, '2026-10-02 09:00:00');
        $this->entityManager->flush();

        self::assertSame([], $this->history()->viewed($this->user->requireId(), 10));
        self::assertSame(
            [$lower->requireId()],
            $this->entryIds($this->history()->favorites($this->user->requireId(), 10)),
        );
    }

    public function testASharedCopyInAFeedTheReaderLeftDoesNotHideTheViewedCopy(): void
    {
        [$followed, $left] = $this->persistPostInAFollowedAndALeftFeed();
        $this->markViewed($followed, '2026-10-02 09:00:00');
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $left,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
        $this->entityManager->flush();

        self::assertSame(
            [$followed->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testAFavoriteCopyInAFeedTheReaderLeftDoesNotHideTheLikedCopy(): void
    {
        [$followed, $left] = $this->persistPostInAFollowedAndALeftFeed();
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $left));
        $this->likeItemOf($followed);
        $this->entityManager->flush();

        self::assertSame(
            [$followed->requireId()],
            $this->entryIds($this->history()->liked($this->user->requireId(), 10)),
        );
    }

    public function testViewedEntriesWithoutAnItemEachKeepTheirLine(): void
    {
        $older = SavedPostFixtures::entry($this->entityManager, $this->feed, 'a', 'https://history.example.com/same');
        $newer = SavedPostFixtures::entry($this->entityManager, $this->feed, 'b', 'https://history.example.com/same');
        $this->markViewed($older, '2026-10-02 09:00:00');
        $this->markViewed($newer, '2026-10-02 10:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$newer->requireId(), $older->requireId()],
            $this->entryIds($this->history()->viewed($this->user->requireId(), 10)),
        );
    }

    public function testALikedPostWithAFavoriteHigherCopyIsOnlyInFavorites(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        StateMarks::markFavorite(SavedPostFixtures::state($this->entityManager, $this->user, $higher));
        $this->likeItemOf($lower);
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
        self::assertSame(
            [$higher->requireId()],
            $this->entryIds($this->history()->favorites($this->user->requireId(), 10)),
        );
    }

    public function testALikedPostWithASharedHigherCopyIsOnlyInShared(): void
    {
        [$lower, $higher] = $this->persistPostInTwoFollowedFeeds();
        EntryShareFixtures::share(
            $this->entityManager,
            $this->user,
            $higher,
            new \DateTimeImmutable('2026-10-02 09:00:00'),
        );
        $this->likeItemOf($lower);
        $this->entityManager->flush();

        self::assertSame([], $this->history()->liked($this->user->requireId(), 10));
        self::assertSame(
            [$higher->requireId()],
            $this->entryIds($this->history()->shared($this->user->requireId(), 10)),
        );
    }

    private function history(): ReadingHistoryRepository
    {
        return new ReadingHistoryRepository($this->entityManager);
    }

    private function persistDatedEntry(Feed $feed, string $guid, string $effectiveAt): Entry
    {
        $at = new \DateTimeImmutable($effectiveAt);
        $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $at, $at);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function subscriptionTo(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function persistEntry(string $guid): Entry
    {
        $url = 'https://history.example.com/' . $guid;

        return SavedPostFixtures::entry($this->entityManager, $this->feed, $guid, $url);
    }

    /** @return array{Entry, Entry} the lower copy in the reader's main feed, the higher in a second followed feed */
    private function persistPostInTwoFollowedFeeds(): array
    {
        $otherFeed = $this->persistSubscribedFeed($this->user, 'https://copies.example.com/feed.xml');
        $lower = $this->persistEntry('copy');
        $higher = SavedPostFixtures::entry($this->entityManager, $otherFeed, 'copy', 'https://copies.example.com/copy');
        $higher->linkTo($this->linkNewItem($lower, CanonicalItemKind::Mastodon));

        return [$lower, $higher];
    }

    /** @return array{Entry, Entry} the copy in the reader's main feed, then one in a feed the reader left */
    private function persistPostInAFollowedAndALeftFeed(): array
    {
        $leftFeed = new Feed('https://left.example.com/feed.xml');
        $this->entityManager->persist($leftFeed);
        $followed = $this->persistEntry('copy');
        $left = SavedPostFixtures::entry($this->entityManager, $leftFeed, 'copy', 'https://left.example.com/copy');
        $left->linkTo($this->linkNewItem($followed, CanonicalItemKind::Mastodon));

        return [$followed, $left];
    }

    private function markViewed(Entry $entry, string $viewedAt): EntryState
    {
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $entry);
        $state->markViewed(new \DateTimeImmutable($viewedAt));

        return $state;
    }

    private function likeItemOf(Entry $entry): void
    {
        $item = $entry->getCanonicalItem();
        self::assertNotNull($item);
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $this->user,
            $item,
            new \DateTimeImmutable('2026-10-02 10:00:00'),
        );
    }

    private function persistStranger(): User
    {
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        return $stranger;
    }

    private function persistSubscribedFeed(User $subscriber, string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Subscription($subscriber, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();

        return $feed;
    }

    private function linkNewItem(Entry $entry, CanonicalItemKind $kind): CanonicalItem
    {
        return LikedPostFixtures::linkNewItem($this->entityManager, $entry, $kind, 'item-' . $entry->requireId());
    }

    private function likeEntry(Entry $entry, string $likedAt): void
    {
        LikedPostFixtures::persistLike(
            $this->entityManager,
            $this->user,
            $this->linkNewItem($entry, CanonicalItemKind::Mastodon),
            new \DateTimeImmutable($likedAt),
        );
    }

    private function likeLocally(Entry $entry, string $likedAt): EntryState
    {
        $state = SavedPostFixtures::state($this->entityManager, $this->user, $entry);

        return StateMarks::markLiked($state, new \DateTimeImmutable($likedAt));
    }

    private function upvoteEntry(Entry $entry, string $votedAt): DiscussionVote
    {
        return LikedPostFixtures::persistVote(
            $this->entityManager,
            $this->user,
            $this->linkNewItem($entry, CanonicalItemKind::Lemmy),
            VoteScore::Up,
            new \DateTimeImmutable($votedAt),
        );
    }

    /**
     * @param list<TitledEntry> $titled
     * @return list<int>
     */
    private function entryIds(array $titled): array
    {
        return array_map(static fn (TitledEntry $item): int => $item->entry->requireId(), $titled);
    }
}

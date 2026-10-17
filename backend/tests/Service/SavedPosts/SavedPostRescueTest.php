<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedEntryOrigin;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\EntryStateRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedPostRescueTest extends DbTestCase
{
    private const string AT = SavedPostFixtures::AT;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->newUser('rescue@example.test');
    }

    public function testAFavouriteOfAnRssFeedMovesToSavedFromFeedsWithItsOriginAndTheRestGoes(): void
    {
        $feed = $this->feed('https://heise.example/feed.xml', 'Heise');
        $feed->setFaviconUrl('https://heise.example/favicon.ico');
        $subscription = $this->subscribe($this->user, $feed);
        $favourite = $this->entry($feed, 'fav', 'https://heise.example/1');
        $this->entry($feed, 'plain', 'https://heise.example/2');
        $this->state($this->user, $favourite)->markFavorite();
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $saved = $this->savedFeed(null);
        $copies = $this->entriesOf($saved);
        self::assertCount(1, $copies);
        self::assertSame('fav', $copies[0]->getGuid());
        self::assertTrue($this->stateOf($copies[0])->isFavorite());
        self::assertEquals(new EntryOrigin('Heise', 'https://heise.example/favicon.ico'), $this->originOf($copies[0]));
        self::assertNull($this->feedAt('https://heise.example/feed.xml'));
        self::assertSame('Saved from feeds', $saved->getTitle());
    }

    public function testAKeptPostOfABlueskyProfileFeedKeepsItsPosterAndItsLike(): void
    {
        $feed = $this->feed('https://bsky.app/profile/did:plc:bob/rss', 'Bob');
        $feed->setSiteUrl('https://bsky.app/profile/did:plc:bob');
        $feed->setImageUrl('https://cdn.example/bob.jpg');
        $subscription = $this->subscribe($this->user, $feed);
        $kept = $this->entry(
            $feed,
            'at://did:plc:bob/app.bsky.feed.post/1',
            'https://bsky.app/profile/did:plc:bob/post/1',
        );
        $this->state($this->user, $kept)->markKept();
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $likedAt = new \DateTimeImmutable(self::AT);
        $this->entityManager->persist(new SocialLike($this->user, $kept, $account, 'at://like/1', $likedAt));
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copy = $this->entriesOf($this->savedFeed(SocialNetwork::Bluesky))[0];
        self::assertSame('Bob', $copy->getAuthor());
        self::assertSame('https://bsky.app/profile/did:plc:bob', $copy->getAuthorUrl());
        self::assertSame('https://cdn.example/bob.jpg', $copy->getAuthorAvatarUrl());
        self::assertTrue($this->stateOf($copy)->isKept());
        self::assertSame(1, $this->entityManager->getRepository(SocialLike::class)->count(['entry' => $copy]));
        self::assertNull($this->originOf($copy));
    }

    public function testAProfileTitleLongerThanThePosterColumnIsCutTo255Characters(): void
    {
        $feed = $this->feed('https://bsky.app/profile/did:plc:long/rss', str_repeat('é', 300));
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'at://did:plc:long/app.bsky.feed.post/1', null))->markKept();
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copy = $this->entriesOf($this->savedFeed(SocialNetwork::Bluesky))[0];
        self::assertSame(str_repeat('é', 255), $copy->getAuthor());
    }

    public function testAnRssTitleLongerThan255CharactersIsCutInTheOrigin(): void
    {
        $feed = $this->feed('https://long.example/feed.xml', str_repeat('ü', 300));
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'long', null))->markFavorite();
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copy = $this->entriesOf($this->savedFeed(null))[0];
        self::assertSame(str_repeat('ü', 255), $this->originOf($copy)?->title);
    }

    public function testThePostSavedTwiceIsMergedIntoOneCopyWithBothFlags(): void
    {
        $home = $this->feed('https://first.example/feed.xml', 'First');
        $list = $this->feed('https://second.example/feed.xml', 'Second');
        $first = $this->subscribe($this->user, $home);
        $second = $this->subscribe($this->user, $list);
        $this->state($this->user, $this->entry($home, 'same-guid', null))->markFavorite();
        $this->state($this->user, $this->entry($list, 'same-guid', null))->markKept();
        $this->entityManager->flush();

        $this->service()->unsubscribeAll([$first, $second]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertCount(1, $copies);
        self::assertTrue($this->stateOf($copies[0])->isFavorite());
        self::assertTrue($this->stateOf($copies[0])->isKept());
    }

    public function testThePostStillInAFeedTheUserKeepsTakesTheFlagsAndNothingIsCopied(): void
    {
        $leaving = $this->feed('https://leaving.example/feed.xml', 'Leaving');
        $kept = $this->feed('https://kept.example/feed.xml', 'Kept');
        $subscription = $this->subscribe($this->user, $leaving);
        $this->subscribe($this->user, $kept);
        $original = $this->entry($leaving, 'a', 'https://news.example/story');
        $twin = $this->entry($kept, 'b', 'https://news.example/story');
        $this->state($this->user, $original)->markFavorite();
        $this->entityManager->flush();
        $twinId = $twin->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        self::assertNull($this->feedAt(SavedFeedUrl::of($this->user->requireId(), null)));
        self::assertTrue($this->stateOfId($twinId)->isFavorite());
    }

    public function testATwinWithAStateOfItsOwnAlsoTakesTheReadMark(): void
    {
        $leaving = $this->feed('https://read-leaving.example/feed.xml', 'Leaving');
        $kept = $this->feed('https://read-kept.example/feed.xml', 'Kept');
        $subscription = $this->subscribe($this->user, $leaving);
        $this->subscribe($this->user, $kept);
        $original = $this->entry($leaving, 'a', 'https://news.example/read');
        $twin = $this->entry($kept, 'b', 'https://news.example/read');
        $state = $this->state($this->user, $original);
        $state->markKept();
        $state->hide(new \DateTimeImmutable(self::AT));
        $this->state($this->user, $twin);
        $this->entityManager->flush();
        $twinId = $twin->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $twinState = $this->stateOfId($twinId);
        self::assertTrue($twinState->isKept());
        self::assertTrue($twinState->isHidden());
        self::assertEquals(new \DateTimeImmutable(self::AT), $twinState->getHiddenAt());
    }

    public function testALegacyReadMarkArrivesReadOnANewCopyWithItsInstantUnknown(): void
    {
        $feed = $this->feed('https://legacy.example/feed.xml', 'Legacy');
        $subscription = $this->subscribe($this->user, $feed);
        $state = $this->state($this->user, $this->entry($feed, 'legacy', null));
        $state->markFavorite();
        $state->restoreReadMark(new BackedUpReadMark(true, null));
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copyState = $this->stateOf($this->entriesOf($this->savedFeed(null))[0]);
        self::assertTrue($copyState->isHidden());
        self::assertNull($copyState->getHiddenAt());
    }

    public function testALegacyReadMarkArrivesReadOnAnUnreadTwin(): void
    {
        $twinId = $this->unsubscribeFromATwinOf(
            new BackedUpReadMark(true, null),
            new BackedUpReadMark(false, null),
        );

        $twinState = $this->stateOfId($twinId);
        self::assertTrue($twinState->isHidden());
        self::assertNull($twinState->getHiddenAt());
    }

    public function testAnAlreadyReadTwinKeepsItsOwnReadInstant(): void
    {
        $twinReadAt = new \DateTimeImmutable('2026-10-01 08:00:00');
        $twinId = $this->unsubscribeFromATwinOf(
            new BackedUpReadMark(true, new \DateTimeImmutable(self::AT)),
            new BackedUpReadMark(true, $twinReadAt),
        );

        $twinState = $this->stateOfId($twinId);
        self::assertTrue($twinState->isHidden());
        self::assertEquals($twinReadAt, $twinState->getHiddenAt());
    }

    public function testAFeedAnotherReaderKeepsLosesOnlyTheFlagsThatMoved(): void
    {
        $shared = $this->feed('https://shared.example/feed.xml', 'Shared');
        $other = $this->newUser('rescue-other@example.test');
        $subscription = $this->subscribe($this->user, $shared);
        $this->subscribe($other, $shared);
        $original = $this->entry($shared, 'shared', 'https://shared.example/1');
        $state = $this->state($this->user, $original);
        $state->markFavorite();
        $state->hide(new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();
        $originalId = $original->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $left = $this->stateOfId($originalId);
        self::assertFalse($left->isFavorite());
        self::assertTrue($left->isHidden());
        $copy = $this->entriesOf($this->savedFeed(null))[0];
        self::assertTrue($this->stateOf($copy)->isFavorite());
        self::assertTrue($this->stateOf($copy)->isHidden());
    }

    public function testUnsubscribingASavedFeedDeletesItsPostsForGood(): void
    {
        $feed = $this->feed('https://gone.example/feed.xml', 'Gone');
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'fav', null))->markFavorite();
        $this->entityManager->flush();
        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($saved);

        $this->service()->unsubscribe($saved);
        $this->entityManager->clear();

        $feeds = $this->entityManager->getRepository(Feed::class);
        self::assertSame(0, $feeds->count(['sourceFormat' => SourceFormat::SAVED]));
        self::assertSame(0, $this->entityManager->getRepository(Entry::class)->count(['guid.value' => 'fav']));
    }

    public function testTwoArticlesSharingAGuidUnderDifferentUrlsAreBothKept(): void
    {
        $first = $this->feed('https://first-blog.example/feed.xml', 'First blog');
        $second = $this->feed('https://second-blog.example/feed.xml', 'Second blog');
        $firstSubscription = $this->subscribe($this->user, $first);
        $secondSubscription = $this->subscribe($this->user, $second);
        $this->state($this->user, $this->entry($first, '1', 'https://first-blog.example/hello'))->markFavorite();
        $this->state($this->user, $this->entry($second, '1', 'https://second-blog.example/other'))->markKept();
        $this->entityManager->flush();
        $secondId = $second->requireId();

        $this->service()->unsubscribeAll([$firstSubscription, $secondSubscription]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertSame(['1', '1#' . $secondId], self::guidsOf($copies));
        self::assertSame('https://second-blog.example/other', $copies[1]->getUrl());
        self::assertTrue($this->stateOf($copies[0])->isFavorite());
        self::assertTrue($this->stateOf($copies[1])->isKept());
        self::assertFalse($this->stateOf($copies[1])->isFavorite());
    }

    public function testARenamedGuidThatItsOwnFeedAlsoCarriesIsCopiedOnceWithBothFlags(): void
    {
        $earlier = $this->feed('https://earlier.example/feed.xml', 'Earlier');
        $earlierSubscription = $this->subscribe($this->user, $earlier);
        $this->state($this->user, $this->entry($earlier, 'a', 'https://earlier.example/a'))->markFavorite();
        $this->entityManager->flush();
        $this->service()->unsubscribe($earlierSubscription);
        $feed = $this->feed('https://later.example/feed.xml', 'Later');
        $subscription = $this->subscribe($this->user, $feed);
        $renamedGuid = 'a#' . $feed->requireId();
        $this->state($this->user, $this->entry($feed, $renamedGuid, 'https://later.example/a'))->markFavorite();
        $this->state($this->user, $this->entry($feed, 'a', 'https://later.example/a'))->markKept();
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertSame(['a', $renamedGuid], self::guidsOf($copies));
        self::assertTrue($this->stateOf($copies[1])->isFavorite());
        self::assertTrue($this->stateOf($copies[1])->isKept());
        self::assertEquals(new EntryOrigin('Later', null), $this->originOf($copies[1]));
    }

    public function testABulkUnsubscribeThatAlsoDropsTheSavedFeedRescuesIntoAFreshOne(): void
    {
        $old = $this->feed('https://old.example/feed.xml', 'Old');
        $oldSubscription = $this->subscribe($this->user, $old);
        $this->state($this->user, $this->entry($old, 'old-fav', null))->markFavorite();
        $this->entityManager->flush();
        $this->service()->unsubscribe($oldSubscription);
        $saved = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($saved);
        $feed = $this->feed('https://new.example/feed.xml', 'New');
        $subscription = $this->subscribe($this->user, $feed);
        $this->state($this->user, $this->entry($feed, 'new-fav', null))->markFavorite();
        $this->entityManager->flush();

        $this->service()->unsubscribeAll([$saved, $subscription]);
        $this->entityManager->clear();

        $copies = $this->entriesOf($this->savedFeed(null));
        self::assertSame(['new-fav'], self::guidsOf($copies));
        self::assertTrue($this->stateOf($copies[0])->isFavorite());
    }

    public function testNothingFlaggedCreatesNoSavedFeed(): void
    {
        $feed = $this->feed('https://unflagged.example/feed.xml', 'Unflagged');
        $subscription = $this->subscribe($this->user, $feed);
        $this->entry($feed, 'plain', null);
        $this->entityManager->flush();

        $this->service()->unsubscribe($subscription);

        self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count(['user' => $this->user]));
    }

    /** @return int the twin's id, after the feed holding the kept original was left and the manager cleared */
    private function unsubscribeFromATwinOf(BackedUpReadMark $originalRead, BackedUpReadMark $twinRead): int
    {
        $leaving = $this->feed('https://twin-leaving.example/feed.xml', 'Leaving');
        $kept = $this->feed('https://twin-kept.example/feed.xml', 'Kept');
        $subscription = $this->subscribe($this->user, $leaving);
        $this->subscribe($this->user, $kept);
        $original = $this->entry($leaving, 'a', 'https://news.example/twin');
        $twin = $this->entry($kept, 'b', 'https://news.example/twin');
        $originalState = $this->state($this->user, $original);
        $originalState->markKept();
        $originalState->restoreReadMark($originalRead);
        $this->state($this->user, $twin)->restoreReadMark($twinRead);
        $this->entityManager->flush();
        $twinId = $twin->requireId();

        $this->service()->unsubscribe($subscription);
        $this->entityManager->clear();

        return $twinId;
    }

    private function newUser(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email);
    }

    private function feed(string $url, string $title): Feed
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable(self::AT));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        return SavedPostFixtures::entry($this->entityManager, $feed, $guid, $url);
    }

    private function state(User $user, Entry $entry): EntryState
    {
        return SavedPostFixtures::state($this->entityManager, $user, $entry);
    }

    private function savedFeed(?SocialNetwork $network): Feed
    {
        $feed = $this->feedAt(SavedFeedUrl::of($this->user->requireId(), $network));
        self::assertNotNull($feed);

        return $feed;
    }

    private function feedAt(string $url): ?Feed
    {
        return $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $url]);
    }

    /**
     * @param list<Entry> $copies
     *
     * @return list<string>
     */
    private static function guidsOf(array $copies): array
    {
        return array_map(static fn (Entry $copy): string => $copy->getGuid(), $copies);
    }

    /** @return list<Entry> */
    private function entriesOf(Feed $feed): array
    {
        return $this->entityManager->getRepository(Entry::class)
            ->findBy(['feed' => $feed], ['id' => 'ASC']);
    }

    private function stateOf(Entry $entry): EntryState
    {
        return $this->stateOfId($entry->requireId());
    }

    private function stateOfId(int $entryId): EntryState
    {
        /** @var EntryStateRepository $states */
        $states = $this->entityManager->getRepository(EntryState::class);
        $state = $states->findOneForUserEntry($this->user->requireId(), $entryId);
        self::assertNotNull($state);

        return $state;
    }

    private function originOf(Entry $copy): ?EntryOrigin
    {
        /** @var SavedEntryOriginRepository $origins */
        $origins = $this->entityManager->getRepository(SavedEntryOrigin::class);

        return $origins->originsByEntryIds([$copy->requireId()])[$copy->requireId()] ?? null;
    }

    private function service(): SubscriptionService
    {
        /** @var SubscriptionService $service */
        $service = self::getContainer()->get(SubscriptionService::class);

        return $service;
    }
}

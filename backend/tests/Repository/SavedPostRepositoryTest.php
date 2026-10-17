<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryGuid;
use App\Entity\EntryOrigin;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SavedEntryOrigin;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\EntryRepository;
use App\Repository\LinkCardRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Repository\SavedPostRepository;
use App\Repository\SocialLikeRepository;
use App\Service\SavedPosts\Model\SavedCopyModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedPostRepositoryTest extends DbTestCase
{
    private const string AT = '2026-10-17 10:00:00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-posts-repo@example.test');
    }

    public function testFlaggedMeansFavouriteOrKeptByThisUserInThisFeed(): void
    {
        $feed = $this->subscribedFeed('https://flagged.example/feed.xml');
        $favourite = $this->entry($feed, 'fav', 'https://flagged.example/1');
        $kept = $this->entry($feed, 'kept', null);
        $readOnly = $this->entry($feed, 'read', 'https://flagged.example/3');
        $this->state($favourite)->markFavorite();
        $this->state($kept)->markKept();
        $this->state($readOnly)->hide(new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $flagged = $this->repository()->flaggedIn($this->user->requireId(), $feed->requireId());

        self::assertSame([$favourite->requireId(), $kept->requireId()], array_keys($flagged));
        self::assertSame($favourite->getUrlHash(), $flagged[$favourite->requireId()]);
        self::assertNull($flagged[$kept->requireId()]);
    }

    public function testAKeptTwinIsTheLowestIdCopyInAFeedTheUserStaysWithAndNeverInALeavingFeed(): void
    {
        $leaving = $this->subscribedFeed('https://leaving.example/feed.xml');
        $alsoLeaving = $this->subscribedFeed('https://also-leaving.example/feed.xml');
        $kept = $this->subscribedFeed('https://kept.example/feed.xml');
        $stranger = new Feed('https://not-subscribed.example/feed.xml');
        $this->entityManager->persist($stranger);
        $original = $this->entry($leaving, 'post', 'https://news.example/story');
        $this->entry($alsoLeaving, 'post', 'https://news.example/story');
        $this->entry($stranger, 'post', 'https://news.example/story');
        $twin = $this->entry($kept, 'post', 'https://news.example/story');
        $this->entry($kept, 'later', 'https://news.example/story');
        $lonely = $this->entry($leaving, 'lonely', 'https://news.example/only-here');
        $this->entityManager->flush();

        $twins = $this->repository()->keptTwinsOf(
            $this->user->requireId(),
            [$original->requireId() => $original->getUrlHash(), $lonely->requireId() => $lonely->getUrlHash()],
            [$leaving->requireId(), $alsoLeaving->requireId()],
        );

        self::assertSame([$original->requireId() => $twin->requireId()], $twins);
    }

    public function testAnEntryWithoutAUrlHashHasNoTwin(): void
    {
        $leaving = $this->subscribedFeed('https://hashless.example/feed.xml');
        $entry = $this->entry($leaving, 'no-url', null);
        $this->entityManager->flush();

        $twins = $this->repository()->keptTwinsOf(
            $this->user->requireId(),
            [$entry->requireId() => null],
            [$leaving->requireId()],
        );

        self::assertSame([], $twins);
    }

    public function testACopyKeepsEveryColumnButItsFeedAndASecondCopyOfTheSameGuidIsSkipped(): void
    {
        $source = $this->subscribedFeed('https://copied.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'guid-1', 'https://copied.example/1');
        $entry->setSummary('Summary');
        $entry->setContentHtml('<p>Body</p>');
        $entry->setAuthor(new EntryAuthor('Jane', 'https://jane.example', null));
        $this->entityManager->flush();

        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);
        $again = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);
        $this->entityManager->clear();

        self::assertCount(1, $copies);
        self::assertSame([], $again);
        $copy = $this->entityManager->find(Entry::class, $copies[$entry->requireId()]);
        self::assertNotNull($copy);
        self::assertSame($saved->requireId(), $copy->getFeed()->requireId());
        self::assertSame('guid-1', $copy->getGuid());
        self::assertSame('https://copied.example/1', $copy->getUrl());
        self::assertSame('Summary', $copy->getSummary());
        self::assertSame('<p>Body</p>', $copy->getContentHtml());
        self::assertSame('Jane', $copy->getAuthor());
        self::assertEquals($entry->getEffectiveDate(), $copy->getEffectiveDate());
        self::assertSame(
            [$entry->requireId() => $copy->requireId()],
            $this->repository()->copiesIn($saved->requireId(), [$entry->requireId()]),
        );
    }

    public function testARenamedCopyKeepsTheArticleUnderItsNewGuid(): void
    {
        $source = $this->subscribedFeed('https://renamed.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, '1', 'https://renamed.example/article');
        $guid = new EntryGuid('1#' . $source->requireId());

        $copyId = $this->repository()->copyRenamed(
            new SavedCopyModel($saved->requireId(), null),
            $entry->requireId(),
            $guid,
        );
        $this->entityManager->clear();

        $copy = $this->entityManager->find(Entry::class, $copyId);
        self::assertSame('1#' . $source->requireId(), $copy?->getGuid());
        self::assertSame($guid->getHash(), $copy->getGuidHash());
        self::assertSame('https://renamed.example/article', $copy->getUrl());
    }

    public function testOriginalsAndHeldRowsAreReadWithTheirHashes(): void
    {
        $source = $this->subscribedFeed('https://held.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'g', 'https://held.example/1');
        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);

        self::assertSame(
            [[
                'id' => $entry->requireId(),
                'guid' => 'g',
                'guidHash' => $entry->getGuidHash(),
                'urlHash' => $entry->getUrlHash(),
                'feedId' => $source->requireId(),
            ]],
            $this->repository()->originalsOf([$entry->requireId()]),
        );
        self::assertSame(
            [$entry->getGuidHash() => ['id' => $copies[$entry->requireId()], 'urlHash' => $entry->getUrlHash()]],
            $this->repository()->heldIn($saved->requireId(), [$entry->getGuidHash(), hash('sha256', 'absent')]),
        );
    }

    public function testAPosterReplacesTheAuthorAndOnlyStandsInForAMissingAvatar(): void
    {
        $profile = $this->subscribedFeed('https://bsky.app/profile/did:plc:bob/rss');
        $saved = $this->savedFeed();
        $plain = $this->entry($profile, 'plain', 'https://bsky.app/profile/did:plc:bob/post/1');
        $repost = $this->entry($profile, 'repost', 'https://bsky.app/profile/did:plc:bob/post/2');
        $repost->setAuthor(new EntryAuthor(null, null, 'https://cdn.example/reposted.jpg'));
        $this->entityManager->flush();
        $poster = new EntryAuthor('Bob', 'https://bsky.app/profile/did:plc:bob', 'https://cdn.example/bob.jpg');

        $copies = $this->repository()->copyInto(
            new SavedCopyModel($saved->requireId(), $poster),
            [$plain->requireId(), $repost->requireId()],
        );
        $this->entityManager->clear();

        $plainCopy = $this->entityManager->find(Entry::class, $copies[$plain->requireId()]);
        $repostCopy = $this->entityManager->find(Entry::class, $copies[$repost->requireId()]);
        self::assertSame('Bob', $plainCopy?->getAuthor());
        self::assertSame('https://bsky.app/profile/did:plc:bob', $plainCopy->getAuthorUrl());
        self::assertSame('https://cdn.example/bob.jpg', $plainCopy->getAuthorAvatarUrl());
        self::assertSame('https://cdn.example/reposted.jpg', $repostCopy?->getAuthorAvatarUrl());
    }

    public function testCompanionsFollowTheCopyAndAnOriginIsRecordedWhenGiven(): void
    {
        $source = $this->subscribedFeed('https://companions.example/feed.xml');
        $saved = $this->savedFeed();
        $entry = $this->entry($source, 'with-card', 'https://companions.example/1');
        $this->entityManager->persist(new LinkCard($entry, 0, new LinkCardContent(
            'https://shared.example/a',
            'Shared',
            null,
            null,
            'shared.example',
        )));
        $this->entityManager->flush();
        $copies = $this->repository()->copyInto(new SavedCopyModel($saved->requireId(), null), [$entry->requireId()]);

        $this->repository()->copyCompanions(
            $copies,
            new EntryOrigin('Companions', 'https://companions.example/favicon.ico'),
        );

        $copyId = $copies[$entry->requireId()];
        /** @var LinkCardRepository $cards */
        $cards = $this->entityManager->getRepository(LinkCard::class);
        self::assertCount(1, $cards->contentsByEntryIds([$copyId])[$copyId] ?? []);
        /** @var SavedEntryOriginRepository $origins */
        $origins = $this->entityManager->getRepository(SavedEntryOrigin::class);
        self::assertEquals(
            [$copyId => new EntryOrigin('Companions', 'https://companions.example/favicon.ico')],
            $origins->originsByEntryIds([$copyId]),
        );
    }

    public function testALikeIsCopiedOnceAndNeverOverAnExistingOne(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $source = $this->subscribedFeed('https://liked.example/feed.xml');
        $from = $this->entry($source, 'liked', 'https://liked.example/1');
        $to = $this->entry($source, 'target', 'https://liked.example/2');
        $likedAt = new \DateTimeImmutable(self::AT);
        $this->entityManager->persist(new SocialLike($this->user, $from, $account, 'at://like/1', $likedAt));
        $this->entityManager->flush();
        /** @var SocialLikeRepository $likes */
        $likes = $this->entityManager->getRepository(SocialLike::class);

        $likes->copyLike($this->user->requireId(), $from->requireId(), $to->requireId());
        $likes->copyLike($this->user->requireId(), $from->requireId(), $to->requireId());

        self::assertSame(1, $likes->count(['entry' => $to]));
        self::assertSame('at://like/1', $likes->findOneFor($this->user, $to)?->getRemoteId());
    }

    public function testEntriesAreFoundByIdInIdOrderAndNoIdsFindNone(): void
    {
        $feed = $this->subscribedFeed('https://by-id.example/feed.xml');
        $first = $this->entry($feed, 'first', 'https://by-id.example/1');
        $this->entry($feed, 'skipped', 'https://by-id.example/2');
        $third = $this->entry($feed, 'third', 'https://by-id.example/3');
        /** @var EntryRepository $entries */
        $entries = $this->entityManager->getRepository(Entry::class);

        $found = $entries->findAllByIds([$third->requireId(), $first->requireId()]);

        $foundIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $found);
        self::assertSame([$first->requireId(), $third->requireId()], $foundIds);
        self::assertSame([], $entries->findAllByIds([]));
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable(self::AT)));

        return $feed;
    }

    private function savedFeed(): Feed
    {
        $feed = new Feed(sprintf('saved:%d', $this->user->requireId()));
        $feed->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $at = new \DateTimeImmutable(self::AT);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, null === $url ? null : hash('sha256', $url));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function state(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    /** Nothing injects it yet, so the test container has removed it. */
    private function repository(): SavedPostRepository
    {
        return new SavedPostRepository($this->entityManager);
    }
}

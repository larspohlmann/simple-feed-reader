<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\PostRemovalRepository;
use App\Repository\RowIds;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\LocalPostRemoval;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\SocialCountsCache;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LocalPostRemovalTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.example/@alice/120';
    private const string STATUS_USERS_URL = 'https://mastodon.example/users/alice/statuses/120';
    private const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/121';

    private DeletedPostTombstones $tombstones;
    private RecordingSearchIndexWriter $index;
    private SocialCountsCache $countsCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tombstones = new DeletedPostTombstones(new ArrayAdapter(), new MockClock('2026-11-01 12:00:00'));
        $this->index = new RecordingSearchIndexWriter();
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
    }

    public function testEveryCopyButASavedOneGoesAndEachIsBuriedInItsFeed(): void
    {
        $user = $this->user('local-removal@example.test');
        $profile = $this->feed('https://mastodon.example/@alice.rss');
        $home = $this->feed('https://other.example/@reader.rss');
        $saved = $this->feed(SavedFeedUrl::of($user->requireId(), SocialNetwork::Mastodon), SourceFormat::SAVED);
        $post = $this->copy($profile, self::STATUS_URL, 'hash-120');
        $copy = $this->copy($home, self::STATUS_USERS_URL, 'hash-120');
        $savedCopy = $this->copy($saved, 'saved:120', 'hash-120');
        $other = $this->copy($profile, 'https://mastodon.example/@alice/121', 'hash-121');
        $this->entityManager->persist(new EntryState($user, $post));
        $this->entityManager->flush();
        $ids = [$post->requireId(), $copy->requireId(), $savedCopy->requireId(), $other->requireId()];
        $this->countsCache->remember(
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
            new SocialCountsModel(1, 0, 0, null),
        );

        $this->removal()->remove($post, []);

        $this->entityManager->clear();
        self::assertSame(
            [false, false, true, true],
            array_map(fn (int $id): bool => null !== $this->entityManager->find(Entry::class, $id), $ids),
        );
        self::assertSame([], $this->entityManager->getRepository(EntryState::class)->findAll());
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL)], $this->tombstones->buriedIn($profile->requireId()));
        self::assertCount(1, $this->tombstones->buriedIn($home->requireId()));
        self::assertSame([], $this->tombstones->buriedIn($saved->requireId()));
        self::assertSame([[$ids[0], $ids[1]]], $this->index->forgets);
        self::assertNull(
            $this->countsCache->find(new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL)),
        );
    }

    public function testTwoCopiesInOneFeedAreBothBuriedThere(): void
    {
        $feed = $this->feed('https://mastodon.example/@alice.rss');
        $post = $this->copy($feed, self::STATUS_URL, 'hash-120');
        $this->copy($feed, self::STATUS_USERS_URL, 'hash-120');

        $this->removal()->remove($post, []);

        self::assertSame(
            [EntryGuid::hashOf(self::STATUS_URL), EntryGuid::hashOf(self::STATUS_USERS_URL)],
            $this->tombstones->buriedIn($feed->requireId()),
        );
    }

    public function testTheRepliesAreBuriedInEveryFeedThePostWasButTheSavedOne(): void
    {
        $user = $this->user('local-removal-replies@example.test');
        $profile = $this->feed('https://mastodon.example/@alice.rss');
        $home = $this->feed('https://other.example/@reader.rss');
        $saved = $this->feed(SavedFeedUrl::of($user->requireId(), SocialNetwork::Mastodon), SourceFormat::SAVED);
        $post = $this->copy($profile, self::STATUS_URL, 'hash-120');
        $this->copy($home, self::STATUS_USERS_URL, 'hash-120');
        $this->copy($saved, 'saved:120', 'hash-120');

        $this->removal()->remove($post, [self::REPLY_URI]);

        $reply = EntryGuid::hashOf(self::REPLY_URI);
        $profileBuried = $this->tombstones->buriedIn($profile->requireId());
        $homeBuried = $this->tombstones->buriedIn($home->requireId());
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL), $reply], $profileBuried);
        self::assertSame([EntryGuid::hashOf(self::STATUS_USERS_URL), $reply], $homeBuried);
        self::assertSame([], $this->tombstones->buriedIn($saved->requireId()));
    }

    public function testOnlyThisCopyLeavesTheOthersUnburied(): void
    {
        $home = $this->feed('social:mastodon:1:home', SourceFormat::MASTODON_API);
        $otherHome = $this->feed('social:mastodon:2:home', SourceFormat::MASTODON_API);
        $post = $this->copy($home, self::STATUS_URL, 'hash-120');
        $sibling = $this->copy($otherHome, self::STATUS_URL, 'hash-120');
        $ids = [$post->requireId(), $sibling->requireId()];

        $this->removal()->removeOnlyThisCopy($post);

        $this->entityManager->clear();
        self::assertSame(
            [false, true],
            array_map(fn (int $id): bool => null !== $this->entityManager->find(Entry::class, $id), $ids),
        );
        self::assertSame([EntryGuid::hashOf(self::STATUS_URL)], $this->tombstones->buriedIn($home->requireId()));
        self::assertSame([], $this->tombstones->buriedIn($otherHome->requireId()));
        self::assertSame([[$ids[0]]], $this->index->forgets);
    }

    public function testAPostWithoutAUrlHashTakesOnlyItself(): void
    {
        $feed = $this->feed('https://mastodon.example/@alice.rss');
        $post = $this->copy($feed, self::STATUS_URL, null);
        $twin = $this->copy($feed, 'https://mastodon.example/@alice/122', null);
        $twinId = $twin->requireId();

        $this->removal()->remove($post, []);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Entry::class, $twinId));
    }

    private function removal(): LocalPostRemoval
    {
        return new LocalPostRemoval(
            new PostRemovalRepository($this->entityManager, new RowIds($this->entityManager)),
            $this->tombstones,
            EntryIndexers::with($this->index),
            $this->countsCache,
        );
    }

    private function feed(string $url, string $sourceFormat = SourceFormat::XML): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function copy(Feed $feed, string $guid, ?string $urlHash): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $guid, $guid, 'Post', $now, $now, $urlHash);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}

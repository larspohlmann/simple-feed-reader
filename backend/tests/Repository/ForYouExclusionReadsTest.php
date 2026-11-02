<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ForYouNetworkExclusion;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\SocialNetwork;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationCandidateRepository;
use App\Repository\RecommendationFeedRow;
use App\Repository\RecommendationItemRepository;
use App\Repository\TitledEntry;
use App\Tests\DbTestCase;

/** A social network switched out of For you drops out of every For you read (#1995). */
final class ForYouExclusionReadsTest extends DbTestCase
{
    private User $user;
    private RecommendationRun $run;
    private Entry $kept;
    private int $nextPosition = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $this->run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $this->run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();

        $this->kept = $this->recommended($this->subscribedFeed('https://kept.example.com/feed.xml'), 'kept');
    }

    public function testASocialFeedIsInEveryReadWhileItsNetworkIsIncluded(): void
    {
        $post = $this->recommended(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'post',
        );

        self::assertEqualsCanonicalizing([$this->kept->getId(), $post->getId()], $this->listedIds());
    }

    public function testExcludingTheNetworkDropsItsFeedsFromEveryRead(): void
    {
        $this->recommended(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'post',
        );
        $this->exclude(SocialNetwork::Bluesky);

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testExcludingOneNetworkLeavesTheOtherIn(): void
    {
        $this->exclude(SocialNetwork::Bluesky);
        $toot = $this->recommended(
            $this->subscribedFeed('https://mastodon.example.com/@a.rss', SocialNetwork::Mastodon),
            'toot',
        );

        self::assertEqualsCanonicalizing([$this->kept->getId(), $toot->getId()], $this->listedIds());
    }

    public function testTheFeedSwitchStillAppliesWhileItsNetworkIsIncluded(): void
    {
        $feed = $this->subscribedFeed('https://bsky.app/profile/bob.bsky.social/rss', SocialNetwork::Bluesky);
        $this->subscriptionOf($feed)->setIncludeInForYou(false);
        $this->recommended($feed, 'post');

        $this->assertOnlyTheKeptEntryIsSeen();
    }

    public function testAnotherUsersExclusionLeavesThisUsersNetworkIn(): void
    {
        $other = new User('other@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->persist(new ForYouNetworkExclusion($other, SocialNetwork::Bluesky));
        $post = $this->recommended(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'post',
        );

        self::assertEqualsCanonicalizing([$this->kept->getId(), $post->getId()], $this->listedIds());
    }

    public function testAnExcludedNetworksLowestIdCopyDoesNotHideTheIncludedOneFromThePool(): void
    {
        $this->exclude(SocialNetwork::Bluesky);
        $this->recommended(
            $this->subscribedFeed('https://bsky.app/profile/alice.bsky.social/rss', SocialNetwork::Bluesky),
            'original',
            urlHash: 'same',
        );
        $copy = $this->recommended($this->subscribedFeed('https://on.example.com/feed.xml'), 'copy', urlHash: 'same');

        self::assertEqualsCanonicalizing(
            [$this->kept->getId(), $copy->getId()],
            $this->entryIds($this->candidates()->newestPool(
                $this->user->requireId(),
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                50,
            )),
        );
    }

    private function assertOnlyTheKeptEntryIsSeen(): void
    {
        $userId = $this->user->requireId();
        $keptId = $this->kept->requireId();

        self::assertSame([$keptId], $this->listedIds(), 'list');
        self::assertSame(1, $this->items()->countForYou($userId), 'count');
        self::assertSame(
            [$keptId],
            $this->items()->unreadEntryIdsForYou($userId, new \DateTimeImmutable('2026-12-31T00:00:00Z')),
            'mark-read',
        );
        self::assertSame(
            [$keptId],
            $this->entryIds($this->candidates()->newestPool(
                $userId,
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                50,
            )),
            'pool',
        );
        self::assertSame(
            [$keptId],
            $this->entryIds($this->candidates()->forIds($userId, $this->allEntryIds())),
            'snapshot',
        );
        self::assertSame(1, $this->candidates()->span($userId, $this->allEntryIds())['total'], 'span');
    }

    private function subscribedFeed(string $url, ?SocialNetwork $network = null): Feed
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
        self::assertSame($network, $feed->getNetwork(), 'the URL classifies the feed on flush');

        return $feed;
    }

    private function subscriptionOf(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function exclude(SocialNetwork $network): void
    {
        $this->entityManager->persist(new ForYouNetworkExclusion($this->user, $network));
        $this->entityManager->flush();
    }

    private function recommended(Feed $feed, string $guid, ?string $urlHash = null): Entry
    {
        $dated = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, 'Title ' . $guid, $dated, $dated, $urlHash);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(
            new RecommendationItem($this->run, $entry, $this->nextPosition++, 'reason ' . $guid),
        );
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<int|null> */
    private function listedIds(): array
    {
        $rows = $this->items()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);

        return array_map(static fn (RecommendationFeedRow $row): ?int => $row->row->entry->getId(), $rows);
    }

    /**
     * @param list<TitledEntry> $titledEntries
     *
     * @return list<int>
     */
    private function entryIds(array $titledEntries): array
    {
        return array_map(static fn (TitledEntry $titled): int => $titled->entry->requireId(), $titledEntries);
    }

    /** @return non-empty-list<int> */
    private function allEntryIds(): array
    {
        $ids = array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->entityManager->getRepository(Entry::class)->findAll(),
        );
        if ($ids === []) {
            self::fail('the fixture holds entries');
        }

        return $ids;
    }

    private function candidates(): RecommendationCandidateRepository
    {
        $repository = self::getContainer()->get(RecommendationCandidateRepository::class);
        self::assertInstanceOf(RecommendationCandidateRepository::class, $repository);

        return $repository;
    }

    private function items(): RecommendationItemRepository
    {
        $repository = $this->entityManager->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $repository);

        return $repository;
    }
}

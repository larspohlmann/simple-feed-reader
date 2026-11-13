<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\NetworkExclusion;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\EntryView;
use App\Enum\FeedGroup;
use App\Enum\ListOrder;
use App\Enum\NetworkExclusionSurface;
use App\Enum\SocialNetwork;
use App\Pagination\EntryCursor;
use App\Repository\DateOrderedPage;
use App\Repository\DuplicateCollapseDql;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowHydrator;
use App\Repository\EntryProjection;
use App\Repository\EntryQuery;
use App\Repository\EntryScopePredicates;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\NetworkScope;
use App\Repository\SearchTermsPredicateBuilder;
use App\Repository\SubscriptionScopeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\QueryRecorder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class EntryListTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);

        $this->subscription = new Subscription(
            $this->user,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->entityManager->persist($this->subscription);

        $this->entityManager->flush();
    }

    private function entry(string $guid, string $published): Entry
    {
        $publishedAt = new \DateTimeImmutable($published);
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Seeds an entry with an explicit list-sort instant, independent of
     * fetch time — the shape the effective-date sort and its keyset actually
     * key on.
     */
    private function entryAt(string $guid, string $createdAt, string $effectiveDate, ?Feed $feed = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function repository(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }

    private function repositoryWithWindow(int $windowSize): EntryListRepository
    {
        return $this->repositoryWith(new DateOrderedPage($windowSize));
    }

    /**
     * A repository wired with the given windows, so the fan-in probe/window branches can be forced through a handful
     * of fixture rows instead of the production sizes.
     */
    private function repositoryWith(DateOrderedPage $dateOrderedPage): EntryListRepository
    {
        $container = self::getContainer();
        /** @var ManagerRegistry $registry */
        $registry = $container->get(ManagerRegistry::class);
        /** @var EntryListRowHydrator $hydrator */
        $hydrator = $container->get(EntryListRowHydrator::class);
        /** @var SearchTermsPredicateBuilder $termsPredicateBuilder */
        $termsPredicateBuilder = $container->get(SearchTermsPredicateBuilder::class);
        /** @var EntryScopePredicates $scope */
        $scope = $container->get(EntryScopePredicates::class);
        /** @var DuplicateCollapseDql $collapse */
        $collapse = $container->get(DuplicateCollapseDql::class);
        /** @var EntryProjection $projection */
        $projection = $container->get(EntryProjection::class);
        /** @var SubscriptionScopeRepository $subscriptionScopes */
        $subscriptionScopes = $container->get(SubscriptionScopeRepository::class);

        return new EntryListRepository(
            $registry,
            $hydrator,
            $termsPredicateBuilder,
            $scope,
            $collapse,
            $dateOrderedPage,
            $projection,
            $subscriptionScopes,
        );
    }

    /**
     * One listForUser() call's rows and every query it issued: the rows match across the probe, window and fallback
     * branches, so only the query shape tells them apart.
     *
     * @return array{rows: list<EntryListRow>, queries: list<string>}
     */
    private function recordedList(EntryListRepository $repository, EntryQuery $query): array
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();
        $rows = $repository->listForUser($query);

        return ['rows' => $rows, 'queries' => $recorder->queries()];
    }

    /**
     * @return array{0: Feed, 1: Tag}
     */
    private function taggedFeedAndSubscription(): array
    {
        $feed = new Feed('https://tagged.example.com/feed.xml');
        $this->entityManager->persist($feed);
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->entityManager->persist($tag);
        $subscription->addTag($tag);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return [$feed, $tag];
    }

    /** @return array{0: Feed, 1: Subscription} */
    private function secondSubscription(string $feedUrl): array
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return [$feed, $subscription];
    }

    private function tagNamed(string $name): Tag
    {
        $tag = new Tag($this->user, $name);
        $this->entityManager->persist($tag);

        return $tag;
    }

    /** @param list<Tag> $tags */
    private function feedTagged(string $feedUrl, array $tags): Feed
    {
        [$feed, $subscription] = $this->secondSubscription($feedUrl);
        foreach ($tags as $tag) {
            $subscription->addTag($tag);
        }
        $this->entityManager->flush();

        return $feed;
    }

    private function entryIn(Feed $feed, string $guid, string $effectiveDate): Entry
    {
        return $this->entryAt($guid, $effectiveDate, $effectiveDate, $feed);
    }

    /**
     * A feed nobody subscribes to. The window probe scans the whole `entry`
     * table regardless of subscription, so a filler row here still counts
     * toward the K-th-newest-entry cutoff without ever matching a scope.
     */
    private function fillerFeed(): Feed
    {
        $feed = new Feed('https://filler.example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function anotherReadersBusyFeed(ListOrder $order): void
    {
        $reader = new User('busy-reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://busy.example.com/feed.xml');
        $this->entityManager->persist($reader);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($reader, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        foreach (range(10, 14) as $day) {
            $this->entryIn($feed, 'busy-' . $day, $this->dayToward($order, $day));
        }
    }

    /** A July date on the 31st-minus-day for oldest-first, so a fixture leads the page in either order. */
    private function dayToward(ListOrder $order, int $day): string
    {
        $dayOfMonth = $order === ListOrder::NewestFirst ? $day : 31 - $day;

        return sprintf('2026-07-%02dT00:00:00Z', $dayOfMonth);
    }

    private function newcomer(): User
    {
        $newcomer = new User('newcomer@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($newcomer);
        $this->entityManager->flush();

        return $newcomer;
    }

    /**
     * The list queries the recorder saw carrying the join-order hint. A result
     * assertion cannot catch the hint — the rows are identical with or without
     * it — so this looks at the wire, like the N+1 guard.
     *
     * @return list<string>
     */
    private function joinPrefixQueries(EntryQuery $query): array
    {
        return array_values(array_filter(
            $this->recordedList($this->repository(), $query)['queries'],
            static fn (string $sql): bool => str_contains($sql, 'JOIN_PREFIX'),
        ));
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<string>
     */
    private function guids(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): string => $row->entry->getGuid(), $rows);
    }

    private function cursorAfter(EntryListRow $row): EntryCursor
    {
        return new EntryCursor(
            $row->entry->getEffectiveDate(),
            $row->entry->requireId(),
        );
    }

    public function testTheChronologicalFanInViewsCarryTheJoinOrderHint(): void
    {
        $userId = $this->user->requireId();

        self::assertCount(1, $this->joinPrefixQueries(new EntryQuery($userId, EntryView::All)));
        self::assertCount(1, $this->joinPrefixQueries(new EntryQuery($userId, EntryView::Unread)));
    }

    public function testOldestFirstReversesTheListIdTieBreakIncluded(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('newest', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');
        $this->entryAt('tied-first', $tied, $tied);
        $this->entryAt('tied-second', $tied, $tied);
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), order: ListOrder::OldestFirst),
        );

        self::assertSame(['older', 'tied-first', 'tied-second', 'newest'], $this->guids($rows));
    }

    public function testOldestFirstKeysetPaginatesAcrossATiedEffectiveDate(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('later', '2026-07-20T00:00:00Z', '2026-07-20T00:00:00Z');
        $this->entryAt('e1', $tied, $tied);
        $this->entryAt('e2', $tied, $tied);
        $this->entryAt('e3', $tied, $tied);
        $userId = $this->user->requireId();

        $page1 = $this->repository()->listForUser(new EntryQuery($userId, limit: 2, order: ListOrder::OldestFirst));
        self::assertSame(['e1', 'e2'], $this->guids($page1));

        $page2 = $this->repository()->listForUser(new EntryQuery(
            $userId,
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertSame(['e3', 'later'], $this->guids($page2));
    }

    public function testViewedViewOldestFirstListsTheEarliestOpenedFirst(): void
    {
        $early = $this->entryAt('early', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $late = $this->entryAt('late', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');
        $earlyState = new EntryState($this->user, $early);
        $earlyState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $lateState = new EntryState($this->user, $late);
        $lateState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->entityManager->persist($earlyState);
        $this->entityManager->persist($lateState);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, order: ListOrder::OldestFirst),
        );

        self::assertSame(['late', 'early'], $this->guids($rows));
    }

    public function testAScopedOrStateDrivenViewDropsTheJoinOrderHint(): void
    {
        $userId = $this->user->requireId();

        self::assertSame([], $this->joinPrefixQueries(
            new EntryQuery($userId, EntryView::All, subscriptionId: $this->subscription->getId()),
        ));
        self::assertSame([], $this->joinPrefixQueries(new EntryQuery($userId, EntryView::Favorites)));
    }

    public function testNewestFirstAndCarriesSubscriptionTitle(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-12T00:00:00Z');

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertCount(2, $rows);
        self::assertSame('Title b', $rows[0]->entry->getTitle());
        self::assertSame($this->subscription->getId(), $rows[0]->subscription->id);
        self::assertSame('Example', $rows[0]->subscription->title);
        self::assertFalse($rows[0]->marks->isHidden);
    }

    public function testSortsByEffectiveDateNotByFetchInstant(): void
    {
        // Both fetched in the same run; the older article sank to its publication date.
        $fetchedAt = '2026-08-14T12:00:00Z';
        $this->entryAt('sunk', $fetchedAt, '2020-03-01T00:00:00Z');
        $this->entryAt('fresh', $fetchedAt, $fetchedAt);

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertSame(['fresh', 'sunk'], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
    }

    public function testNewerEffectiveDateSortsFirst(): void
    {
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $this->entryAt('newer', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertSame('newer', $rows[0]->entry->getGuid());
        self::assertSame('older', $rows[1]->entry->getGuid());
    }

    public function testKeysetPaginatesCorrectlyAcrossATiedEffectiveDate(): void
    {
        // A refresh run shares one effective date, so a page boundary inside the tie, ordered by id DESC alone, must
        // neither skip nor repeat a row. The older row after the tie exercises applyCursor()'s `effectiveDate <` half.
        $tied = '2026-07-01T00:00:00Z';
        $firstTied = $this->entryAt('e1', $tied, $tied);
        $secondTied = $this->entryAt('e2', $tied, $tied);
        $thirdTied = $this->entryAt('e3', $tied, $tied);
        $older = $this->entryAt('older', '2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z');

        $page1 = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), limit: 2));
        self::assertSame([$thirdTied->getGuid(), $secondTied->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $page2 = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), cursor: $cursor, limit: 2));

        self::assertSame([$firstTied->getGuid(), $older->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page2,
        ));
    }

    public function testWatermarkFoldsIntoIsReadAndUnreadFilter(): void
    {
        $this->entry('old', '2026-07-05T00:00:00Z');
        $this->entry('new', '2026-07-20T00:00:00Z');
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->entityManager->flush();

        $all = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        $byGuid = [];
        foreach ($all as $row) {
            $byGuid[$row->entry->getGuid()] = $row;
        }
        self::assertTrue($byGuid['old']->marks->isHidden);   // under the watermark
        self::assertFalse($byGuid['new']->marks->isHidden);  // above it

        $unread = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertCount(1, $unread);
        self::assertSame('new', $unread[0]->entry->getGuid());
    }

    public function testExplicitStateBeatsWatermark(): void
    {
        $entry = $this->entry('x', '2026-07-05T00:00:00Z');
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        // Explicitly unread despite being under the watermark.
        $state = new EntryState($this->user, $entry);
        $state->markUnread();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $unread = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertCount(1, $unread);
        self::assertFalse($unread[0]->marks->isHidden);
    }

    public function testFavoritesAndKeptViews(): void
    {
        $favorite = $this->entry('fav', '2026-07-05T00:00:00Z');
        $kept = $this->entry('kept', '2026-07-06T00:00:00Z');
        $this->entry('plain', '2026-07-07T00:00:00Z');

        $favoriteState = new EntryState($this->user, $favorite);
        $favoriteState->markFavorite();
        $keptState = new EntryState($this->user, $kept);
        $keptState->markKept();
        $this->entityManager->persist($favoriteState);
        $this->entityManager->persist($keptState);
        $this->entityManager->flush();

        $favorites = $this->repository()->listForUser(new EntryQuery(
            $this->user->requireId(),
            view: EntryView::Favorites,
        ));
        self::assertCount(1, $favorites);
        self::assertSame('fav', $favorites[0]->entry->getGuid());
        self::assertTrue($favorites[0]->marks->isFavorite);

        $kepts = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Kept));
        self::assertCount(1, $kepts);
        self::assertSame('kept', $kepts[0]->entry->getGuid());
        self::assertTrue($kepts[0]->marks->isKept);
        self::assertFalse($kepts[0]->marks->isFavorite);
    }

    public function testAnEntryWithoutAStateRowCarriesNoMarks(): void
    {
        $this->entry('plain', '2026-07-07T00:00:00Z');

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->marks->isFavorite);
        self::assertFalse($rows[0]->marks->isKept);
    }

    public function testViewedViewOrdersByViewTimeNotPublishDate(): void
    {
        // 'early' was published FIRST but opened LAST; 'late' the other way round.
        // A publish-date sort would put 'late' on top — the viewed history must
        // put 'early' on top, because that is the more recently opened one.
        $early = $this->entryAt('early', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $late = $this->entryAt('late', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');
        // 'plain' is never opened, so it must not appear in the viewed list.
        $this->entryAt('plain', '2026-07-01T00:00:00Z', '2026-07-30T00:00:00Z');

        $earlyState = new EntryState($this->user, $early);
        $earlyState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $lateState = new EntryState($this->user, $late);
        $lateState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->entityManager->persist($earlyState);
        $this->entityManager->persist($lateState);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Viewed));

        self::assertSame(['early', 'late'], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
        self::assertEquals(new \DateTimeImmutable('2026-08-05T09:00:00Z'), $rows[0]->marks->viewedAt);
    }

    public function testViewedViewKeysetPaginatesByViewTime(): void
    {
        $first = $this->entryAt('first', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $second = $this->entryAt('second', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');

        $firstState = new EntryState($this->user, $first);
        $firstState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $secondState = new EntryState($this->user, $second);
        $secondState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->entityManager->persist($firstState);
        $this->entityManager->persist($secondState);
        $this->entityManager->flush();

        $page1 = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, limit: 1),
        );
        self::assertCount(1, $page1);
        self::assertSame('first', $page1[0]->entry->getGuid());

        // The cursor carries the last row's viewedAt — the instant the viewed
        // view keyset compares against, not the entry's effectiveDate.
        $cursor = new EntryCursor(
            $page1[0]->marks->viewedAt ?? throw new \LogicException('A viewed row must carry a viewedAt.'),
            $page1[0]->entry->requireId(),
        );
        $page2 = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, cursor: $cursor, limit: 1),
        );
        self::assertCount(1, $page2);
        self::assertSame('second', $page2[0]->entry->getGuid());
    }

    public function testTagFilter(): void
    {
        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->entityManager->persist($otherFeed);
        $otherSubscription = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->entityManager->persist($tag);
        $otherSubscription->addTag($tag);
        $this->entityManager->persist($otherSubscription);
        $this->entityManager->flush();

        $this->entry('untagged', '2026-07-05T00:00:00Z');
        $tagged = new Entry(
            $otherFeed,
            'tagged',
            'https://other.example.com/1',
            'Tagged',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $tagged->setPublishedAt(new \DateTimeImmutable('2026-07-06T00:00:00Z'));
        $this->entityManager->persist($tagged);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));
        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
    }

    public function testATagListsNoFeedThatOnlyCarriesAnotherTag(): void
    {
        [$newsFeed, $news] = $this->taggedFeedAndSubscription();
        [$techFeed, $techSubscription] = $this->secondSubscription('https://tech.example.com/feed.xml');
        $tech = new Tag($this->user, 'tech');
        $this->entityManager->persist($tech);
        $techSubscription->addTag($tech);
        $this->entityManager->flush();
        $this->entryIn($newsFeed, 'news', '2026-07-05T00:00:00Z');
        $this->entryIn($techFeed, 'tech', '2026-07-06T00:00:00Z');

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), tagId: $news->getId()));

        self::assertSame(['news'], $this->guids($rows));
    }

    public function testANetworkListsOnlyItsFeedsExcludedOnesIncluded(): void
    {
        [$profile, $profileSubscription] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $profileSubscription->changeInclusions(new InclusionChange(allItems: false));
        [$mastodon] = $this->secondSubscription(NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->entityManager->flush();
        $this->entry('plain', '2026-07-05T00:00:00Z');
        $this->entryIn($profile, 'bluesky', '2026-07-06T00:00:00Z');
        $this->entryIn($mastodon, 'mastodon', '2026-07-07T00:00:00Z');

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), networkScope: NetworkScope::whole(SocialNetwork::Bluesky)),
        );

        self::assertSame(['bluesky'], $this->guids($rows));
    }

    public function testANetworkSwitchedOutOfAllItemsLeavesAllButStaysInItsOwnView(): void
    {
        [$profile] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $at = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $this->entityManager->persist(
            new Entry($this->feed, 'plain-copy', 'https://example.com/copy', 'Plain copy', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->persist(
            new Entry($profile, 'network-copy', 'https://bsky.app/copy', 'Network copy', $at, $at, 'shared-url-hash'),
        );
        $this->entryIn($profile, 'network-only', '2026-07-06T00:00:00Z');
        $this->entityManager->persist(
            new NetworkExclusion($this->user, SocialNetwork::Bluesky, NetworkExclusionSurface::AllItems),
        );
        $this->entityManager->flush();

        $all = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        $unread = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        $network = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), networkScope: NetworkScope::whole(SocialNetwork::Bluesky)),
        );

        self::assertSame(['plain-copy'], $this->guids($all));
        self::assertSame([], $all[0]->duplicates);
        self::assertSame(['plain-copy'], $this->guids($unread));
        self::assertSame(['network-only', 'network-copy'], $this->guids($network));
    }

    public function testATagSwitchedOutOfAllItemsTakesEveryFeedBelowItOutButStaysInItsOwnView(): void
    {
        $switchedOff = $this->tagNamed('off');
        $switchedOff->changeInclusions(new InclusionChange(allItems: false));
        $included = $this->tagNamed('on');
        $below = $this->tagNamed('below');
        $below->setParent($switchedOff);
        $offOnly = $this->feedTagged('https://off.example.com/feed.xml', [$switchedOff]);
        $belowOnly = $this->feedTagged('https://below.example.com/feed.xml', [$below]);
        $offAndOn = $this->feedTagged('https://both.example.com/feed.xml', [$switchedOff, $included]);
        $onOnly = $this->feedTagged('https://on.example.com/feed.xml', [$included]);
        $at = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $this->entityManager->persist(
            new Entry($this->feed, 'untagged', 'https://example.com/copy', 'Untagged', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->persist(
            new Entry($offOnly, 'off', 'https://off.example.com/copy', 'Off', $at, $at, 'shared-url-hash'),
        );
        $this->entryIn($belowOnly, 'below', '2026-07-06T00:00:00Z');
        $this->entryIn($offAndOn, 'both', '2026-07-07T00:00:00Z');
        $this->entryIn($onOnly, 'on', '2026-07-08T00:00:00Z');

        $all = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        $unread = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        $ownView = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), tagId: $switchedOff->requireId()),
        );

        self::assertSame(['on', 'untagged'], $this->guids($all));
        self::assertSame([], $all[1]->duplicates);
        self::assertSame(['on', 'untagged'], $this->guids($unread));
        self::assertSame(['both', 'below', 'off'], $this->guids($ownView));
    }

    public function testANetworkWithoutFeedsListsNothing(): void
    {
        $this->entry('plain', '2026-07-05T00:00:00Z');

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), networkScope: NetworkScope::whole(SocialNetwork::Mastodon)),
        );

        self::assertSame([], $rows);
    }

    public function testANetworkIsWindowedLikeATag(): void
    {
        [$profile] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $firstEntry = $this->entryIn($profile, 's1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($profile, 's2', '2026-07-09T00:00:00Z');
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $query = new EntryQuery(
            $this->user->requireId(),
            limit: 2,
            networkScope: NetworkScope::whole(SocialNetwork::Bluesky),
        );

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], $this->guids($recorded['rows']));
    }

    public function testACopyOutsideTheNetworkNeverHidesTheNetworksOwnCopy(): void
    {
        [$profile] = $this->secondSubscription(NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $at = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $this->entityManager->persist(
            new Entry($this->feed, 'plain-copy', 'https://example.com/copy', 'Plain copy', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->persist(
            new Entry($profile, 'network-copy', 'https://bsky.app/copy', 'Network copy', $at, $at, 'shared-url-hash'),
        );
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), networkScope: NetworkScope::whole(SocialNetwork::Bluesky)),
        );

        self::assertSame(['network-copy'], $this->guids($rows));
        self::assertSame([], $rows[0]->duplicates);
    }

    public function testSubscriptionFilterAndCursorPaginate(): void
    {
        $this->entry('e1', '2026-07-10T00:00:00Z');
        $this->entry('e2', '2026-07-11T00:00:00Z');
        $this->entry('e3', '2026-07-12T00:00:00Z');

        $page1 = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $this->subscription->getId(), limit: 2),
        );
        self::assertCount(2, $page1);
        self::assertSame('e3', $page1[0]->entry->getGuid());
        self::assertSame('e2', $page1[1]->entry->getGuid());

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $page2 = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), cursor: $cursor, limit: 2));
        self::assertCount(1, $page2);
        self::assertSame('e1', $page2[0]->entry->getGuid());
    }

    public function testSubscriptionFilterExcludesEntriesFromTheUsersOtherSubscription(): void
    {
        $ownEntry = $this->entry('own', '2026-07-10T00:00:00Z');

        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->entityManager->persist($otherFeed);
        $otherSubscription = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($otherSubscription);
        $otherEntry = new Entry(
            $otherFeed,
            'other',
            'https://other.example.com/1',
            'Other',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-11T00:00:00Z'),
        );
        $this->entityManager->persist($otherEntry);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $this->subscription->getId()),
        );

        self::assertSame([$ownEntry->getId()], array_map(static fn ($row) => $row->entry->getId(), $rows));
    }

    public function testExcludesFeedsTheUserDoesNotSubscribeTo(): void
    {
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $this->entityManager->persist($strangerFeed);
        $orphanCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $orphan = new Entry($strangerFeed, 'orphan', null, 'Orphan', $orphanCreatedAt, $orphanCreatedAt);
        $orphan->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        foreach ($rows as $row) {
            self::assertNotSame('orphan', $row->entry->getGuid());
        }
    }

    public function testCarriesTheViewedFlag(): void
    {
        $viewed = $this->entry('viewed', '2026-07-05T00:00:00Z');
        $this->entry('untouched', '2026-07-06T00:00:00Z');

        $state = new EntryState($this->user, $viewed);
        $state->markViewed(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        $byGuid = [];
        foreach ($rows as $row) {
            $byGuid[$row->entry->getGuid()] = $row->marks->isViewed();
        }
        self::assertTrue($byGuid['viewed']);
        self::assertFalse($byGuid['untouched']);
    }

    public function testExcludedFeedHiddenFromAllAndUnreadButVisibleInExplicitScopes(): void
    {
        $entryA = $this->entry('a', '2026-07-10T00:00:00Z');

        $excludedFeed = new Feed('https://excluded.example.com/feed.xml');
        $this->entityManager->persist($excludedFeed);
        $excludedSubscription = new Subscription(
            $this->user,
            $excludedFeed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $excludedSubscription->changeInclusions(new InclusionChange(allItems: false));
        $tag = new Tag($this->user, 'news');
        $this->entityManager->persist($tag);
        $excludedSubscription->addTag($tag);
        $this->entityManager->persist($excludedSubscription);
        $entryB = new Entry(
            $excludedFeed,
            'b',
            'https://excluded.example.com/b',
            'Title b',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-11T00:00:00Z'),
        );
        $this->entityManager->persist($entryB);
        $favoriteState = new EntryState($this->user, $entryB);
        $favoriteState->markFavorite();
        $this->entityManager->persist($favoriteState);
        $this->entityManager->flush();

        $all = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $all));

        $unread = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $unread));

        $own = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $excludedSubscription->getId()),
        );
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $own));

        $tagged = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $tagged));

        $favorites = $this->repository()->listForUser(new EntryQuery(
            $this->user->requireId(),
            view: EntryView::Favorites,
        ));
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $favorites));
    }

    public function testStateIsScopedToTheCaller(): void
    {
        // A second subscriber to the SAME feed/entry. Their read + favorite
        // state must never bleed into our view — the LEFT JOIN is keyed on
        // es.user, so we see only our own (absent) state.
        $entry = $this->entry('shared', '2026-07-05T00:00:00Z');

        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($stranger);
        $this->entityManager->persist(
            new Subscription($stranger, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $strangerState = new EntryState($stranger, $entry);
        $strangerState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $strangerState->markFavorite();
        $this->entityManager->persist($strangerState);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));
        self::assertCount(1, $rows);
        self::assertSame('shared', $rows[0]->entry->getGuid());
        self::assertFalse($rows[0]->marks->isHidden, 'must not inherit the stranger\'s read flag');
        self::assertFalse($rows[0]->marks->isFavorite, 'must not inherit the stranger\'s favorite flag');
    }

    public function testASurvivorNeverListsItselfAndAnInScopeHiddenCopyAppearsAsADuplicate(): void
    {
        $survivor = new Entry(
            $this->feed,
            'survivor',
            'https://example.com/survivor',
            'Survivor',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->entityManager->persist($survivor);
        $hiddenCopy = new Entry(
            $this->feed,
            'hidden-copy',
            'https://example.com/hidden-copy',
            'Hidden copy',
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            'shared-url-hash',
        );
        $this->entityManager->persist($hiddenCopy);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertCount(1, $rows);
        self::assertSame('survivor', $rows[0]->entry->getGuid());
        self::assertSame(
            ['hidden-copy'],
            array_map(static fn ($duplicate) => $duplicate->entry->getGuid(), $rows[0]->duplicates),
        );
    }

    public function testACopyOutsideTheQueryScopeIsNotListedAsADuplicate(): void
    {
        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->entityManager->persist($otherFeed);
        $otherSubscription = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->entityManager->persist($tag);
        $otherSubscription->addTag($tag);
        $this->entityManager->persist($otherSubscription);

        $outOfScopeCopy = new Entry(
            $this->feed,
            'out-of-scope-copy',
            'https://example.com/out-of-scope-copy',
            'Out of scope copy',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->entityManager->persist($outOfScopeCopy);
        $taggedEntry = new Entry(
            $otherFeed,
            'tagged',
            'https://other.example.com/tagged',
            'Tagged',
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            'shared-url-hash',
        );
        $this->entityManager->persist($taggedEntry);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));

        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
        self::assertSame([], $rows[0]->duplicates);
    }

    public function testAttachDuplicatesCarriesTheDuplicateLookupHint(): void
    {
        $survivor = new Entry(
            $this->feed,
            'survivor',
            'https://example.com/survivor',
            'Survivor',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->entityManager->persist($survivor);
        $this->entityManager->persist($this->favorited($survivor));
        $this->entityManager->flush();

        // The favorites view drops the chronological-fan-in hint entirely
        // (testAScopedOrStateDrivenViewDropsTheJoinOrderHint), so a JOIN_PREFIX
        // here can only come from attachDuplicates's own, unconditional hint.
        self::assertCount(
            1,
            $this->joinPrefixQueries(new EntryQuery($this->user->requireId(), view: EntryView::Favorites)),
        );
    }

    private function favorited(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();

        return $state;
    }

    /**
     * @return iterable<string, array{EntryView, ListOrder}>
     */
    public static function chronologicalViewsInBothOrders(): iterable
    {
        foreach ([EntryView::All, EntryView::Unread] as $view) {
            foreach (ListOrder::cases() as $order) {
                yield $view->value . ', ' . $order->value => [$view, $order];
            }
        }
    }

    /**
     * @return iterable<string, array{ListOrder}>
     */
    public static function bothOrders(): iterable
    {
        foreach (ListOrder::cases() as $order) {
            yield $order->value => [$order];
        }
    }

    public function testAnAllViewBelowTheWindowSizeRunsTheProbeThenOneHintedPage(): void
    {
        $this->entry('a', '2026-07-05T00:00:00Z');

        $queries = $this->recordedList(
            $this->repository(),
            new EntryQuery($this->user->requireId(), EntryView::All),
        )['queries'];

        self::assertCount(2, $queries, 'a null probe must run exactly one hinted page query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[0], 'the probe itself is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);
    }

    #[DataProvider('chronologicalViewsInBothOrders')]
    public function testADenseReaderStaysHintedInsideTheWindow(EntryView $view, ListOrder $order): void
    {
        $this->entryIn($this->feed, 'own-1', $this->dayToward($order, 10));
        $this->entryIn($this->feed, 'own-2', $this->dayToward($order, 9));
        $this->entryIn($this->fillerFeed(), 'filler', $this->dayToward($order, 8));
        $query = new EntryQuery($this->user->requireId(), $view, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][1]);
        self::assertSame(['own-1', 'own-2'], $this->guids($recorded['rows']));
    }

    #[DataProvider('chronologicalViewsInBothOrders')]
    public function testASparseReaderNextToABusyFeedFallsBackToThePlainUnhintedQuery(
        EntryView $view,
        ListOrder $order,
    ): void {
        $this->anotherReadersBusyFeed($order);
        $this->entryIn($this->feed, 'own-1', $this->dayToward($order, 5));
        $this->entryIn($this->feed, 'own-2', $this->dayToward($order, 4));
        $query = new EntryQuery($this->user->requireId(), $view, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(4, $recorded['queries'], 'a short window over a sparse scope falls back plain');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][1], 'the windowed attempt is hinted');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][3], 'the fallback is unhinted');
        self::assertSame(['own-1', 'own-2'], $this->guids($recorded['rows']));
    }

    #[DataProvider('chronologicalViewsInBothOrders')]
    public function testAReaderWithoutSubscriptionsGetsTheUnhintedPlanNextToABusyFeed(
        EntryView $view,
        ListOrder $order,
    ): void {
        $this->anotherReadersBusyFeed($order);
        $query = new EntryQuery($this->newcomer()->requireId(), $view, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(4, $recorded['queries'], 'an empty window over an empty scope falls back plain');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][3], 'the fallback is unhinted');
        self::assertSame([], $recorded['rows']);
    }

    #[DataProvider('bothOrders')]
    public function testACaughtUpReaderWithAScopeBeyondThePlainScopeLimitKeepsTheHintedWalk(ListOrder $order): void
    {
        foreach (range(10, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(5, $recorded['queries'], 'a scope beyond the plain limit skips the wide window');
        self::assertStringContainsString('LIMIT 1 OFFSET 2', $recorded['queries'][2], 'the scope probe at the window');
        self::assertStringContainsString('LIMIT 1 OFFSET 4', $recorded['queries'][3], 'the scope probe at the limit');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][3], 'the scope probe is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][4], 'a big scope stays hinted');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testACaughtUpReaderWithinThePlainScopeLimitTriesTheWideWindowThenFallsBackPlain(
        ListOrder $order,
    ): void {
        foreach (range(11, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach (range(8, 10) as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(7, $recorded['queries'], 'a short wide window falls back to a seventh, plain query');
        self::assertStringContainsString('LIMIT 1 OFFSET 5', $recorded['queries'][4], 'the wide window probe');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5], 'the wide window is hinted');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][6], 'the fallback is unhinted');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testAllViewWithinThePlainScopeLimitTriesTheWideWindowThenFallsBackPlain(ListOrder $order): void
    {
        foreach (range(6, 8) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach (range(9, 13) as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::All, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(7, $recorded['queries'], 'the All scope counts read entries, so the wide window is tried');
        self::assertStringContainsString('LIMIT 1 OFFSET 5', $recorded['queries'][4], 'the wide window probe');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5], 'the wide window is hinted');
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][6], 'the fallback is unhinted');
        self::assertSame(['read-8', 'read-7'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testACursoredUnreadPageCountsTheWideWindowFromTheCursor(ListOrder $order): void
    {
        $head = $this->entryIn($this->feed, 'head', $this->dayToward($order, 20));
        foreach ([13, 12] as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach (range(9, 11) as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery(
            $this->user->requireId(),
            EntryView::Unread,
            cursor: new EntryCursor($head->getEffectiveDate(), $head->requireId()),
            limit: 2,
            order: $order,
        );

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(7, $recorded['queries'], 'a short wide window falls back to a seventh, plain query');
        self::assertStringContainsString('LIMIT 1 OFFSET 5', $recorded['queries'][4], 'the wide window probe');
        self::assertStringContainsString('effective_date = ?', $recorded['queries'][4], 'it starts at the cursor');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testUnreadEntriesJustPastTheWindowComeFromTheHintedWideWindow(ListOrder $order): void
    {
        foreach ([13, 12] as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $filler = $this->fillerFeed();
        foreach ([11, 10] as $day) {
            $this->entryIn($filler, 'filler-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'unread-9', $this->dayToward($order, 9));
        $this->entryIn($this->feed, 'unread-8', $this->dayToward($order, 8));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 5, 4)), $query);

        self::assertCount(6, $recorded['queries'], 'a full wide window never falls back');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5], 'the wide window is hinted');
        self::assertSame(['unread-9', 'unread-8'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testAWideWindowBeyondTheTableWalksTheRestHinted(ListOrder $order): void
    {
        foreach (range(11, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery($this->user->requireId(), EntryView::Unread, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWith(new DateOrderedPage(2, 10, 4)), $query);

        self::assertCount(6, $recorded['queries'], 'no wide edge means one hinted walk over the few rows left');
        self::assertStringContainsString('JOIN_PREFIX', $recorded['queries'][5]);
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testAFeedExcludedFromAllItemsDoesNotCountTowardTheScope(ListOrder $order): void
    {
        [$excludedFeed, $excluded] = $this->secondSubscription('https://excluded.example.com/feed.xml');
        $excluded->changeInclusions(new InclusionChange(allItems: false));
        $this->entityManager->flush();
        foreach (range(10, 14) as $day) {
            $this->entryIn($excludedFeed, 'excluded-' . $day, $this->dayToward($order, $day));
        }
        $this->entryIn($this->feed, 'own-1', $this->dayToward($order, 5));
        $query = new EntryQuery($this->user->requireId(), EntryView::All, limit: 2, order: $order);

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(4, $recorded['queries']);
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][3], 'only the All scope is counted');
        self::assertSame(['own-1'], $this->guids($recorded['rows']));
    }

    #[DataProvider('bothOrders')]
    public function testADenseFeedGroupWithAShortWindowFallsBackPlainWithoutAScopeProbe(ListOrder $order): void
    {
        foreach (range(10, 13) as $day) {
            $read = $this->entryIn($this->feed, 'read-' . $day, $this->dayToward($order, $day));
            $this->entityManager->persist($this->hidden($read));
        }
        $this->entryIn($this->feed, 'unread', $this->dayToward($order, 2));
        $this->entityManager->flush();
        $query = new EntryQuery(
            $this->user->requireId(),
            EntryView::Unread,
            limit: 2,
            order: $order,
            feedGroup: FeedGroup::Feeds,
        );

        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        $listQueries = array_values(array_filter(
            $recorded['queries'],
            static fn (string $sql): bool => str_contains($sql, 'FROM entry'),
        ));
        self::assertCount(3, $listQueries, 'a narrowed scope falls back without probing its size');
        self::assertStringNotContainsString('JOIN_PREFIX', $listQueries[2], 'a narrowed scope falls back plain');
        self::assertSame(['unread'], $this->guids($recorded['rows']));
    }

    public function testFewerThanWindowSizeRowsBeyondCursorRunsASingleHintedQuery(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');

        $repository = $this->repositoryWithWindow(5);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 10);

        $recorded = $this->recordedList($repository, $query);
        $queries = $recorded['queries'];
        self::assertCount(2, $queries, 'a null probe must still run exactly one hinted page query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[0], 'the probe itself is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);

        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testDenseTagWindowedAttemptEqualsThePlainQuerysPage(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repository, $query);
        $queries = $recorded['queries'];
        self::assertCount(2, $queries, 'a full windowed page must never fall back');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);

        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testSparseTagFallsBackToThePlainUnhintedQuery(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'f1', '2026-07-10T00:00:00Z');
        $this->entryIn($filler, 'f2', '2026-07-09T00:00:00Z');
        $this->entryIn($filler, 'f3', '2026-07-08T00:00:00Z');
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-05T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-04T00:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repository, $query);
        $queries = $recorded['queries'];
        self::assertCount(3, $queries, 'a short windowed page must fall back to a third, plain query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[2], 'the fallback is deliberately unhinted');

        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testScopeWithFewerRowsThanTheLimitReturnsAShortPageWithoutLossOrDuplication(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'f1', '2026-07-10T00:00:00Z');
        $this->entryIn($filler, 'f2', '2026-07-09T00:00:00Z');
        $this->entryIn($filler, 'f3', '2026-07-08T00:00:00Z');
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-05T00:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $rows = $repository->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 5));

        self::assertSame([$firstEntry->getGuid()], array_map(static fn ($row) => $row->entry->getGuid(), $rows));
    }

    public function testATiedWindowBoundaryIsIncludedWholeAndNeverTriggersAnUnnecessaryFallback(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        // t2 and t3 share an effectiveDate and straddle the window pivot; t3 is
        // created second so it sorts first on the id DESC tiebreak.
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-05T00:00:00Z');
        $thirdEntry = $this->entryIn($feed, 't3', '2026-07-05T00:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 3);

        $recorded = $this->recordedList($repository, $query);
        self::assertCount(
            2,
            $recorded['queries'],
            'an inclusive >= window bound must keep the tie in one windowed attempt',
        );

        self::assertSame([$firstEntry->getGuid(), $thirdEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testCursorContinuesAWindowedPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-14T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-13T00:00:00Z');
        $thirdEntry = $this->entryIn($feed, 't3', '2026-07-12T00:00:00Z');
        $fourthEntry = $this->entryIn($feed, 't4', '2026-07-11T00:00:00Z');
        $this->entryIn($feed, 't5', '2026-07-10T00:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repository->listForUser(new EntryQuery($userId, tagId: $tag->getId(), limit: 2));
        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $query = new EntryQuery($userId, tagId: $tag->getId(), cursor: $cursor, limit: 2);

        // A probe without the cursor predicate still falls back to a correct
        // page (just via a 3rd query), so only the query count catches it.
        $recorded = $this->recordedList($repository, $query);
        self::assertCount(2, $recorded['queries'], 'the cursor must keep this page windowed, not fall back');
        self::assertSame([$thirdEntry->getGuid(), $fourthEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testOldestFirstDenseTagWindowedAttemptEqualsThePlainQuerysPage(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $this->entryIn($feed, 't1', '2026-07-09T00:00:00Z');
        $this->entryIn($feed, 't2', '2026-07-10T00:00:00Z');
        $this->entryIn($feed, 't3', '2026-07-11T00:00:00Z');

        $query = new EntryQuery(
            $this->user->requireId(),
            tagId: $tag->getId(),
            limit: 2,
            order: ListOrder::OldestFirst,
        );
        $recorded = $this->recordedList($this->repositoryWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertSame(['t1', 't2'], $this->guids($recorded['rows']));
    }

    public function testOldestFirstCursorContinuesAWindowedPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        foreach (['t1' => 10, 't2' => 11, 't3' => 12, 't4' => 13, 't5' => 14] as $guid => $day) {
            $this->entryIn($feed, $guid, sprintf('2026-07-%02dT00:00:00Z', $day));
        }
        $repository = $this->repositoryWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repository->listForUser(
            new EntryQuery($userId, tagId: $tag->getId(), limit: 2, order: ListOrder::OldestFirst),
        );
        self::assertSame(['t1', 't2'], $this->guids($page1));

        $recorded = $this->recordedList($repository, new EntryQuery(
            $userId,
            tagId: $tag->getId(),
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertCount(2, $recorded['queries'], 'the cursor must keep this page windowed, not fall back');
        self::assertSame(['t3', 't4'], $this->guids($recorded['rows']));
    }

    public function testCursorContinuesAFallbackPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $thirdEntry = $this->entryIn($feed, 't3', '2026-07-08T00:00:00Z');
        $fourthEntry = $this->entryIn($feed, 't4', '2026-07-07T00:00:00Z');
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'g1', '2026-07-08T18:00:00Z');
        $this->entryIn($filler, 'g2', '2026-07-08T12:00:00Z');

        $repository = $this->repositoryWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repository->listForUser(new EntryQuery($userId, tagId: $tag->getId(), limit: 2));
        self::assertSame([$firstEntry->getGuid(), $secondEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $query = new EntryQuery($userId, tagId: $tag->getId(), cursor: $cursor, limit: 2);

        $recorded = $this->recordedList($repository, $query);
        self::assertCount(3, $recorded['queries'], 'the second page must independently fall back');
        self::assertSame([$thirdEntry->getGuid(), $fourthEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testUnreadViewOnADenseTagWhoseNewestEntriesAreAllReadFallsBack(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $firstEntry = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $secondEntry = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $thirdEntry = $this->entryIn($feed, 't3', '2026-07-08T00:00:00Z');
        $fourthEntry = $this->entryIn($feed, 't4', '2026-07-07T00:00:00Z');
        $this->entityManager->persist($this->hidden($firstEntry));
        $this->entityManager->persist($this->hidden($secondEntry));
        $this->entityManager->flush();

        $repository = $this->repositoryWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), view: EntryView::Unread, tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repository, $query);
        self::assertCount(
            3,
            $recorded['queries'],
            'the windowed rows the pivot admits are all read, so it must fall back',
        );
        self::assertStringNotContainsString('JOIN_PREFIX', $recorded['queries'][2], 'a narrowed tag falls back plain');

        $rows = $recorded['rows'];
        self::assertSame([$thirdEntry->getGuid(), $fourthEntry->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
        self::assertFalse($rows[0]->marks->isHidden);
        self::assertFalse($rows[1]->marks->isHidden);
    }

    public function testGetRowForUserReturnsTheRowOfASubscribedEntry(): void
    {
        $entry = $this->entry('owned-row', '2026-07-02T00:00:00Z');

        $row = $this->repository()->getRowForUser($this->user->requireId(), $entry->requireId());

        self::assertSame($entry->requireId(), $row->entry->requireId());
    }

    public function testGetRowForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-row');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repository()->getRowForUser($this->user->requireId(), $entry->requireId());
    }

    public function testGetOneSubscribedForUserReturnsASubscribedEntry(): void
    {
        $entry = $this->entry('owned-entry', '2026-07-02T00:00:00Z');

        self::assertSame(
            $entry,
            $this->repository()->getOneSubscribedForUser($this->user->requireId(), $entry->requireId()),
        );
    }

    public function testGetOneSubscribedForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-entry');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repository()->getOneSubscribedForUser($this->user->requireId(), $entry->requireId());
    }

    public function testAnEmptyFeedGroupIsAnEmptyPageWithoutAnEntryQuery(): void
    {
        $this->entry('untagged-post', '2026-07-02T00:00:00Z');

        $recorded = $this->recordedList(
            $this->repository(),
            new EntryQuery($this->user->requireId(), feedGroup: FeedGroup::Tags),
        );

        self::assertSame([], $recorded['rows']);
        self::assertCount(1, $recorded['queries']);
        self::assertStringNotContainsString('FROM entry', $recorded['queries'][0]);
    }

    public function testAFeedGroupCopyIsNotCollapsedUnderACopyOutsideTheGroup(): void
    {
        [$taggedFeed] = $this->taggedFeedAndSubscription();
        $this->entityManager->persist($this->sharedUrlCopy($taggedFeed, 'tagged-copy'));
        $this->entityManager->persist($this->sharedUrlCopy($this->feed, 'plain-copy'));
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(
            new EntryQuery($this->user->requireId(), feedGroup: FeedGroup::Feeds),
        );

        self::assertSame(['plain-copy'], $this->guids($rows));
    }

    private function sharedUrlCopy(Feed $feed, string $guid): Entry
    {
        return new Entry(
            $feed,
            $guid,
            'https://example.com/shared-article',
            'Shared article',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-article-hash',
        );
    }

    private function entryOfAnUnsubscribedFeed(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.xml');
        $this->entityManager->persist($feed);

        return $this->entryAt($guid, '2026-07-01T00:00:00Z', '2026-07-01T00:00:00Z', $feed);
    }

    private function hidden(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));

        return $state;
    }
}

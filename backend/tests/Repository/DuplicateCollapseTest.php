<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\EntryView;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Service\Library\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostEntries;

final class DuplicateCollapseTest extends DbTestCase
{
    private User $user;
    private Feed $feedA;
    private Feed $feedB;
    private Subscription $subscriptionA;
    private Subscription $subscriptionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('dupe@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feedA = $this->feed('https://a.example/feed.xml', 'Feed A');
        $this->feedB = $this->feed('https://b.example/feed.xml', 'Feed B');
        $this->subscriptionA = $this->subscribe($this->user, $this->feedA);
        $this->subscriptionB = $this->subscribe($this->user, $this->feedB);
        $this->entityManager->flush();
    }

    public function testCopiesOfOneItemShowOnce(): void
    {
        [$lower, $higher] = $this->persistCopiesOfOnePost();
        $tag = new Tag($this->user, 'lemmy');
        $this->entityManager->persist($tag);
        $this->subscriptionA->addTag($tag);
        $this->subscriptionB->addTag($tag);
        $search = new SavedSearch($this->user, 'post', false);
        $this->entityManager->persist($search);
        foreach ([$lower, $higher] as $copy) {
            $this->entityManager->persist(new SavedSearchEntry($search, $copy, new \DateTimeImmutable('2026-07-06')));
        }
        $this->entityManager->flush();
        $userId = $this->user->requireId();

        self::assertSame([$lower->getId()], self::collectIds(
            $this->repository()->listForUser(new EntryQuery($userId, view: EntryView::All)),
        ));
        self::assertSame([$lower->getId()], self::collectIds(
            $this->repository()->listForUser(new EntryQuery($userId, view: EntryView::All, tagId: $tag->getId())),
        ));
        self::assertSame([$lower->getId()], self::collectIds($this->savedSearchEntries()->listMembers(
            new SavedSearchListQuery($userId, [$search->requireId()]),
        )));
    }

    public function testFeedViewStillShowsItsCopy(): void
    {
        [, $higher] = $this->persistCopiesOfOnePost();

        $rows = $this->repository()->listForUser(new EntryQuery(
            $this->user->requireId(),
            view: EntryView::All,
            subscriptionId: $this->subscriptionB->getId(),
        ));

        self::assertSame([$higher->getId()], self::collectIds($rows));
    }

    public function testCrossFeedDuplicateCollapsesToTheLowerId(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::All));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
        self::assertNotSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testAUserOnOnlyOneSideSeesTheirOwnCopy(): void
    {
        $solo = new User('solo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($solo);
        $this->subscribe($solo, $this->feedB);
        $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $onlyCopy = $this->entry(
            $this->feedB,
            'b-guid',
            'https://tagesschau.de/x',
            'urlhash-x',
            '2026-07-05T10:00:00Z',
        );
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($solo->requireId(), view: EntryView::All));

        self::assertCount(1, $rows);
        self::assertSame($onlyCopy->getId(), $rows[0]->entry->getId());
    }

    public function testUnreadViewSurvivorIsTheUnreadCopyWhenTheLowerIdIsRead(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $read = new EntryState($this->user, $lower);
        $read->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($read);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));

        self::assertCount(1, $rows);
        self::assertSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testNullUrlHashNeverGroups(): void
    {
        $one = $this->entry($this->feedA, 'a-guid', null, null, '2026-07-05T09:00:00Z');
        $two = $this->entry($this->feedB, 'b-guid', null, null, '2026-07-05T10:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::All));

        self::assertCount(2, $rows);
        $ids = array_map(static fn ($row) => $row->entry->getId(), $rows);
        self::assertContains($one->getId(), $ids);
        self::assertContains($two->getId(), $ids);
    }

    public function testSearchGroupsWithinTheMatchedTermsOnly(): void
    {
        // Same urlHash, but only the higher-id copy's title matches the search.
        $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z')
            ->setHeadline(Headline::fromFeed('Lübecker Hauptbahnhof gesperrt'));
        $match = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $match->setHeadline(Headline::fromFeed('Lübeck Zugausfälle am Wochenende'));
        $this->entityManager->flush();

        $rows = $this->repository()->searchForUser(new EntrySearchQuery(
            $this->user->requireId(),
            SearchTermsModel::fromInput('Zugausfälle'),
        ));

        self::assertCount(1, $rows);
        self::assertSame($match->getId(), $rows[0]->entry->getId());
    }

    public function testRowsByIdsGroupsWithinTheGivenIdSet(): void
    {
        $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->entityManager->flush();

        // Meilisearch matched only the higher-id copy; the lower-id copy is not in
        // the set, so it must not win and delete the article from the results.
        $rows = $this->repository()->rowsByIdsForUser($this->user->requireId(), [$higher->requireId()]);

        self::assertCount(1, $rows);
        self::assertSame($higher->getId(), $rows[0]->entry->getId());
    }

    public function testSearchCollapsesTwoMatchingCopiesToTheLowerId(): void
    {
        // Both copies' titles match the search term, so both are in the outer
        // selection: only the collapse call can reduce this to one row.
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $lower->setHeadline(Headline::fromFeed('Lübeck Zugausfälle heute Morgen'));
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $higher->setHeadline(Headline::fromFeed('Lübeck Zugausfälle am Wochenende'));
        $this->entityManager->flush();

        $rows = $this->repository()->searchForUser(new EntrySearchQuery(
            $this->user->requireId(),
            SearchTermsModel::fromInput('Zugausfälle'),
        ));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
    }

    public function testRowsByIdsCollapsesTwoCopiesInTheSetToTheLowerId(): void
    {
        // Both ids are passed in, so both are in the outer selection: only the
        // collapse call can reduce this to one row.
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->rowsByIdsForUser(
            $this->user->requireId(),
            [$lower->requireId(), $higher->requireId()],
        );

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
    }

    public function testSurvivorCarriesTheInScopeHiddenCopy(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::All));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
        self::assertCount(1, $rows[0]->duplicates);
        self::assertSame($higher->getId(), $rows[0]->duplicates[0]->entry->getId());
        self::assertSame([], $rows[0]->duplicates[0]->duplicates); // flattened
    }

    public function testUnreadViewFooterOmitsAReadCopy(): void
    {
        $lower = $this->entry($this->feedA, 'a-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T09:00:00Z');
        $higher = $this->entry($this->feedB, 'b-guid', 'https://tagesschau.de/x', 'urlhash-x', '2026-07-05T10:00:00Z');
        $read = new EntryState($this->user, $higher);
        $read->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($read);
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));

        self::assertCount(1, $rows);
        self::assertSame($lower->getId(), $rows[0]->entry->getId());
        self::assertSame([], $rows[0]->duplicates); // the read copy is out of unread scope
    }

    /** @return array{Entry, Entry} the lower-id copy, then the other, both without a URL hash */
    private function persistCopiesOfOnePost(): array
    {
        $lower = LemmyPostEntries::buildEntry($this->feedA, guid: 'local-copy', publishedAt: '2026-07-05T09:00:00Z');
        $item = LemmyPostEntries::linkItem($lower);
        $higher = LemmyPostEntries::buildEntry($this->feedB, guid: 'all-copy', publishedAt: '2026-07-05T10:00:00Z');
        $higher->linkTo($item);
        $this->entityManager->persist($item);
        $this->entityManager->persist($lower);
        $this->entityManager->persist($higher);
        $this->entityManager->flush();

        return [$lower, $higher];
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int|null>
     */
    private static function collectIds(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): ?int => $row->entry->getId(), $rows);
    }

    private function savedSearchEntries(): SavedSearchEntryRepository
    {
        $entries = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $entries);

        return $entries;
    }

    private function repository(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
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
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function entry(Feed $feed, string $guid, ?string $url, ?string $urlHash, string $effective): Entry
    {
        $at = new \DateTimeImmutable($effective);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, $urlHash);
        $this->entityManager->persist($entry);

        return $entry;
    }
}

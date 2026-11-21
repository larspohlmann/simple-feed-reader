<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\EntryView;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use App\Service\Library\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostEntries;

final class CrossPostCollapseTest extends DbTestCase
{
    private User $user;
    private Feed $feedA;
    private Feed $feedB;
    private Subscription $subscriptionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('crosspost@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feedA = $this->persistFeed('https://a.example/feed.xml');
        $this->feedB = $this->persistFeed('https://b.example/feed.xml');
        $this->persistSubscription($this->feedA);
        $this->subscriptionB = $this->persistSubscription($this->feedB);
        $this->entityManager->flush();
    }

    public function testACrossPostGroupShowsAsOneRowLedByTheEarliestPublished(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->entityManager->flush();

        self::assertSame([$leader->getId()], $this->listIds(EntryView::All));
    }

    public function testEarliestPublishedLeadsEvenWithTheHighestId(): void
    {
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), limit: 1));

        self::assertSame([$leader->getId()], self::collectIds($rows));
    }

    public function testACopyOfTheLeaderInAnotherFeedStillCollapsesByItem(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCopy($leader, $this->feedB);
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->entityManager->flush();

        self::assertSame([$leader->getId()], $this->listIds(EntryView::All));
    }

    public function testEqualPublishedMembersAreLedByTheLowerId(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T09:00:00Z');
        $this->entityManager->flush();

        self::assertSame([$leader->getId()], $this->listIds(EntryView::All));
    }

    public function testUnreadViewShowsTheUnreadCopyOfTheLeaderWhenItsLowerIdCopyIsRead(): void
    {
        $readCopy = $this->persistCrossPost(
            $this->feedA,
            'https://lemmy.example/post/1',
            'h1',
            '2026-11-14T09:00:00Z',
        );
        $unreadCopy = $this->persistCopy($readCopy, $this->feedB);
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->persistRead($readCopy);
        $this->entityManager->flush();

        self::assertSame([$unreadCopy->getId()], $this->listIds(EntryView::Unread));
    }

    public function testUnreadViewHidesTheGroupOnceItsLeaderIsRead(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->persistRead($leader);
        $this->entityManager->flush();

        self::assertSame([], $this->listIds(EntryView::Unread));
    }

    public function testUnreadViewShowsTheLeaderWhileItIsUnread(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $member = $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->persistRead($member);
        $this->entityManager->flush();

        self::assertSame([$leader->getId()], $this->listIds(EntryView::Unread));
    }

    public function testFavoritesViewShowsAFavouritedLaterMember(): void
    {
        $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $member = $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $favourite = new EntryState($this->user, $member);
        $favourite->markFavorite();
        $this->entityManager->persist($favourite);
        $this->entityManager->flush();

        self::assertSame([$member->getId()], $this->listIds(EntryView::Favorites));
    }

    public function testASubscriptionViewWithoutTheLeaderShowsItsOwnMember(): void
    {
        $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $member = $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery(
            $this->user->requireId(),
            subscriptionId: $this->subscriptionB->getId(),
        ));

        self::assertSame([$member->getId()], self::collectIds($rows));
    }

    public function testUngroupedItemsAreUntouched(): void
    {
        $earlier = $this->persistPost($this->feedA, 'https://lemmy.example/post/1', '2026-11-14T09:00:00Z');
        $later = $this->persistPost($this->feedB, 'https://lemmy.example/post/2', '2026-11-14T10:00:00Z');
        $this->entityManager->flush();

        self::assertSame([$later->getId(), $earlier->getId()], $this->listIds(EntryView::All));
    }

    public function testSearchCollapsesAGroupToItsLeader(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->entityManager->flush();

        self::assertSame([$leader->getId()], $this->searchIds(unread: false));
    }

    public function testUnreadSearchHidesTheGroupOnceItsLeaderIsRead(): void
    {
        $leader = $this->persistCrossPost($this->feedA, 'https://lemmy.example/post/1', 'h1', '2026-11-14T09:00:00Z');
        $this->persistCrossPost($this->feedB, 'https://lemmy.example/post/2', 'h1', '2026-11-14T10:00:00Z');
        $this->persistRead($leader);
        $this->entityManager->flush();

        self::assertSame([], $this->searchIds(unread: true));
    }

    public function testAPageStillHoldsLimitRows(): void
    {
        foreach (['g1', 'g2', 'g3'] as $index => $hash) {
            $this->persistCrossPost(
                $this->feedA,
                'https://lemmy.example/post/' . $hash . '-lead',
                $hash,
                \sprintf('2026-11-14T%02d:00:00Z', 10 + $index * 2),
            );
            $this->persistCrossPost(
                $this->feedB,
                'https://lemmy.example/post/' . $hash . '-member',
                $hash,
                \sprintf('2026-11-14T%02d:00:00Z', 11 + $index * 2),
            );
        }
        $this->persistPost($this->feedA, 'https://lemmy.example/post/single-1', '2026-11-14T08:00:00Z');
        $this->persistPost($this->feedB, 'https://lemmy.example/post/single-2', '2026-11-14T07:00:00Z');
        $this->entityManager->flush();

        $rows = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), limit: 4));

        self::assertCount(4, $rows);
        $hashes = array_filter(array_map(
            static fn (EntryListRow $row): ?string => $row->entry->getCanonicalItem()?->getCrossPostHash(),
            $rows,
        ));
        self::assertSame(array_unique($hashes), $hashes);
    }

    /**
     * @return list<int|null>
     */
    private function listIds(EntryView $view): array
    {
        return self::collectIds($this->repository()->listForUser(new EntryQuery($this->user->requireId(), $view)));
    }

    /**
     * @return list<int|null>
     */
    private function searchIds(bool $unread): array
    {
        return self::collectIds($this->repository()->searchForUser(new EntrySearchQuery(
            $this->user->requireId(),
            SearchTermsModel::fromInput('post'),
            unread: $unread,
        )));
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

    private function persistCrossPost(Feed $feed, string $page, string $hash, string $publishedAt): Entry
    {
        $entry = $this->persistPost($feed, $page, $publishedAt);
        $entry->getCanonicalItem()?->recordCrossPostHash($hash);

        return $entry;
    }

    private function persistCopy(Entry $original, Feed $feed): Entry
    {
        $item = $original->getCanonicalItem();
        self::assertNotNull($item);
        $publishedAt = $original->getPublishedAt();
        self::assertNotNull($publishedAt);
        $copy = LemmyPostEntries::buildEntry(
            $feed,
            $item->getCanonicalKey(),
            publishedAt: $publishedAt->format(\DATE_ATOM),
        );
        $copy->setPublishedAt($publishedAt);
        $copy->linkTo($item);
        $this->entityManager->persist($copy);

        return $copy;
    }

    private function persistPost(Feed $feed, string $page, string $publishedAt): Entry
    {
        $entry = LemmyPostEntries::buildEntry($feed, $page, publishedAt: $publishedAt);
        $entry->setPublishedAt(new \DateTimeImmutable($publishedAt));
        $this->entityManager->persist(LemmyPostEntries::linkItem($entry, $page));
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function persistRead(Entry $entry): void
    {
        $read = new EntryState($this->user, $entry);
        $read->hide(new \DateTimeImmutable('2026-11-14T12:00:00Z'));
        $this->entityManager->persist($read);
    }

    private function repository(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }

    private function persistFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function persistSubscription(Feed $feed): Subscription
    {
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($subscription);

        return $subscription;
    }
}

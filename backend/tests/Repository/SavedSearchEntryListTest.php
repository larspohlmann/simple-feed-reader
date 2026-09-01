<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The combined saved-search list: every saved search's matches in one stream.
 * ASCII terms only — the suite runs on SQLite, whose LIKE folds ASCII case
 * alone.
 */
final class SavedSearchEntryListTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    public function testListsMatchesOfEverySavedSearch(): void
    {
        $climate = $this->entry('a', 'Climate report', effectiveDate: '2026-07-10T00:00:00Z');
        $rocket = $this->entry('b', 'Rocket launch', effectiveDate: '2026-07-09T00:00:00Z');
        $this->entry('c', 'Nothing to see', effectiveDate: '2026-07-08T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket']));

        self::assertSame(
            [$climate->getId(), $rocket->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testAnEntryMatchingTwoSavedSearchesIsListedOnce(): void
    {
        $both = $this->entry('a', 'Climate rocket', effectiveDate: '2026-07-10T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket']));

        self::assertCount(1, $rows);
        self::assertSame($both->getId(), $rows[0]->entry->getId());
    }

    public function testNoSavedSearchesListsNothingRatherThanEverything(): void
    {
        $this->entry('a', 'Climate report');

        self::assertSame([], $this->repo()->listForSavedSearches($this->query([])));
    }

    public function testTheCursorWalksTheWholeStreamAcrossAPageBoundary(): void
    {
        $first = $this->entry('a', 'Climate one', effectiveDate: '2026-07-10T00:00:00Z');
        $second = $this->entry('b', 'Rocket two', effectiveDate: '2026-07-09T00:00:00Z');
        $third = $this->entry('c', 'Climate three', effectiveDate: '2026-07-08T00:00:00Z');

        $page = $this->repo()->listForSavedSearches($this->query(['climate', 'rocket'], limit: 2));
        self::assertSame(
            [$first->getId(), $second->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $page),
        );

        $cursor = new EntryCursor($second->getEffectiveDate(), (int) $second->getId());
        $next = $this->repo()->listForSavedSearches(
            $this->query(['climate', 'rocket'], limit: 2, cursor: $cursor),
        );

        self::assertSame(
            [$third->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $next),
        );
    }

    public function testOnlyUnreadDropsAReadEntry(): void
    {
        $read = $this->entry('a', 'Climate read', effectiveDate: '2026-07-10T00:00:00Z');
        $unread = $this->entry('b', 'Climate unread', effectiveDate: '2026-07-09T00:00:00Z');
        $this->hide($read);

        $rows = $this->repo()->listForSavedSearches($this->query(['climate'], onlyUnread: true));

        self::assertSame(
            [$unread->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testUnreadMatchIdsStopAtTheWatermark(): void
    {
        $old = $this->entry('a', 'Climate old', effectiveDate: '2026-07-08T00:00:00Z');
        $newer = $this->entry('b', 'Climate new', effectiveDate: '2026-07-11T00:00:00Z');

        $ids = $this->repo()->unreadMatchIdsForSavedSearches(
            $this->query(['climate']),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame([$old->getId()], $ids);
        self::assertNotContains($newer->getId(), $ids);
    }

    /** @param list<string> $terms */
    private function query(
        array $terms,
        bool $onlyUnread = false,
        int $limit = 50,
        ?EntryCursor $cursor = null,
    ): SavedSearchEntryQuery {
        return new SavedSearchEntryQuery(
            (int) $this->user->getId(),
            array_map(
                static fn (string $term): SearchTerms => SearchTerms::fromTermAndMode(
                    $term,
                    SearchMode::Substring,
                ),
                $terms,
            ),
            $onlyUnread,
            $cursor,
            $limit,
        );
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function entry(
        string $guid,
        string $title,
        ?string $summary = null,
        string $effectiveDate = '2026-07-10T00:00:00Z',
    ): Entry {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $entry->setSummary($summary);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function repo(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }
}

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
use App\Service\Search\SavedSearchTerm;
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
            (int) $this->user->getId(),
            $this->savedSearches(['climate']),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame([$old->getId()], $ids);
        self::assertNotContains($newer->getId(), $ids);
    }

    public function testATwoWordSearchRequiresBothWordsOredAgainstAOneWordSearch(): void
    {
        $matchesTwoWordSearch = $this->entry('a', 'Climate report today', effectiveDate: '2026-07-10T00:00:00Z');
        $matchesOneWordSearch = $this->entry('b', 'Rocket launch', effectiveDate: '2026-07-09T00:00:00Z');
        $matchesNeither = $this->entry('c', 'Climate only', effectiveDate: '2026-07-08T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->query(['climate report', 'rocket']));

        self::assertSame(
            [$matchesTwoWordSearch->getId(), $matchesOneWordSearch->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testAWholeWordSavedSearchAlongsideASubstringSavedSearch(): void
    {
        $wholeWordMatch = $this->entry('a', 'A stray cat appeared', effectiveDate: '2026-07-10T00:00:00Z');
        $this->entry('b', 'The category changed', effectiveDate: '2026-07-09T00:00:00Z');
        $substringMatch = $this->entry('c', 'A hotdog stand opened', effectiveDate: '2026-07-08T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->queryWithModes([
            ['cat', SearchMode::WholeWord],
            ['dog', SearchMode::Substring],
        ]));

        self::assertSame(
            [$wholeWordMatch->getId(), $substringMatch->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    /**
     * Two whole-word searches ORed together: the shape that overflowed SQLite
     * 3.45's parser stack while the boundary rule was a REPLACE chain (#584).
     */
    public function testTwoWholeWordSavedSearchesListTogether(): void
    {
        $cat = $this->entry('a', 'A stray cat appeared', effectiveDate: '2026-07-10T00:00:00Z');
        $dog = $this->entry('b', 'The dog barked', effectiveDate: '2026-07-09T00:00:00Z');
        $this->entry('c', 'The category changed', effectiveDate: '2026-07-08T00:00:00Z');

        $rows = $this->repo()->listForSavedSearches($this->queryWithModes([
            ['cat', SearchMode::WholeWord],
            ['dog', SearchMode::WholeWord],
        ]));

        self::assertSame(
            [$cat->getId(), $dog->getId()],
            array_map(static fn ($row): ?int => $row->entry->getId(), $rows),
        );
    }

    public function testUnreadMatchIdsForNoSavedSearchesReturnsNothingRatherThanEveryUnreadId(): void
    {
        $this->entry('a', 'Climate report');

        $ids = $this->repo()->unreadMatchIdsForSavedSearches(
            (int) $this->user->getId(),
            $this->savedSearches([]),
            new \DateTimeImmutable('2026-07-31T00:00:00Z'),
        );

        self::assertSame([], $ids);
    }

    public function testReportsWhichSavedSearchMatchedEachEntry(): void
    {
        $climate = $this->entry('a', 'Climate report', effectiveDate: '2026-07-10T00:00:00Z');
        $rocket = $this->entry('b', 'Rocket launch', effectiveDate: '2026-07-09T00:00:00Z');

        $matched = $this->repo()->matchedSavedSearchIds(
            [(int) $climate->getId(), (int) $rocket->getId()],
            $this->savedSearches(['climate', 'rocket']),
        );

        self::assertSame([(int) $climate->getId() => 10, (int) $rocket->getId() => 20], $matched);
    }

    public function testAnEntryMatchingTwoSavedSearchesReportsTheFirst(): void
    {
        $both = $this->entry('a', 'Climate rocket');

        $matched = $this->repo()->matchedSavedSearchIds(
            [(int) $both->getId()],
            $this->savedSearches(['climate', 'rocket']),
        );

        self::assertSame([(int) $both->getId() => 10], $matched);
    }

    public function testNoEntriesNeedsNoQuery(): void
    {
        self::assertSame(
            [],
            $this->repo()->matchedSavedSearchIds([], $this->savedSearches(['climate'])),
        );
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
            $this->savedSearches($terms),
            $onlyUnread,
            $cursor,
            $limit,
        );
    }

    /**
     * The saved searches a test runs against, keyed 10, 20, … so an assertion
     * on a reported id cannot pass by matching a position instead.
     *
     * @param list<string> $terms
     *
     * @return list<SavedSearchTerm>
     */
    private function savedSearches(array $terms): array
    {
        return array_map(
            static fn (int $position, string $term): SavedSearchTerm => new SavedSearchTerm(
                ($position + 1) * 10,
                SearchTerms::fromTermAndMode($term, SearchMode::Substring),
            ),
            array_keys($terms),
            $terms,
        );
    }

    /** @param list<array{0: string, 1: SearchMode}> $termsAndModes */
    private function queryWithModes(array $termsAndModes): SavedSearchEntryQuery
    {
        return new SavedSearchEntryQuery(
            (int) $this->user->getId(),
            array_map(
                static fn (int $position, array $termAndMode): SavedSearchTerm => new SavedSearchTerm(
                    ($position + 1) * 10,
                    SearchTerms::fromTermAndMode(...$termAndMode),
                ),
                array_keys($termsAndModes),
                $termsAndModes,
            ),
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

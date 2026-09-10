<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\DatabaseSavedSearchEntries;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * DatabaseSavedSearchEntries: the existing LIKE query behind the shared
 * interface. It must reproduce today's controller output exactly — a match
 * count equal to the row count and no continuation row — so
 * SavedSearchEntriesWithFallback can hand either path's result to
 * EntryPage::withMatchCount unchanged.
 */
final class DatabaseSavedSearchEntriesTest extends DbTestCase
{
    private User $user;

    private Feed $feed;

    private int $savedSearchId;

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

        $savedSearch = new SavedSearch($this->user, 'angular', false);
        $this->em->persist($savedSearch);
        $this->em->flush();

        $this->savedSearchId = $savedSearch->getId() ?? 0;
    }

    private function entry(string $guid, string $title, string $effectiveDate = '2026-07-10T00:00:00Z'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testReturnsTheRepositoryRowsWithBadgesAndACountEqualToTheRows(): void
    {
        $this->entry('a', 'Angular one', '2026-07-10T00:00:00Z');
        $this->entry('b', 'Angular two', '2026-07-09T00:00:00Z');
        $this->entry('c', 'Nothing to see', '2026-07-08T00:00:00Z');

        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        $query = new SavedSearchEntryQuery(
            userId: $this->user->getId() ?? 0,
            savedSearches: [new SavedSearchTerm($this->savedSearchId, SearchTerms::fromInput('angular'))],
        );

        $result = (new DatabaseSavedSearchEntries($repository))->list($query);

        self::assertCount(2, $result->rows);
        self::assertSame(\count($result->rows), $result->matchCount);
        self::assertNull($result->continuationRow);
        foreach ($result->rows as $row) {
            self::assertSame($this->savedSearchId, $result->savedSearchIds[(int) $row->entry->getId()]);
        }
    }
}

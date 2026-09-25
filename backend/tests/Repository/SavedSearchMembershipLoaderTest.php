<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchMembershipLoader;
use App\Tests\DbTestCase;

final class SavedSearchMembershipLoaderTest extends DbTestCase
{
    public function testLoaderAttachesSavedSearchesToRows(): void
    {
        $user = $this->user('loader-member@example.com');
        $entry = $this->entry($this->feed());
        $older = $this->savedSearchWithMember($user, 'climate', $entry);
        $newer = $this->savedSearchWithMember($user, 'rocket', $entry);

        $loader = self::getContainer()->get(SavedSearchMembershipLoader::class);
        self::assertInstanceOf(SavedSearchMembershipLoader::class, $loader);
        $enriched = $loader->loadInto([$this->row($entry)], $user->requireId());

        $expectedIds = [$newer->requireId(), $older->requireId()];
        self::assertSame($expectedIds, array_column($enriched[0]->savedSearches, 'id'));
    }

    public function testReturnsEveryOwnedSearchPerEntryNewestFirst(): void
    {
        $user = $this->user('member@example.com');
        $entry = $this->entry($this->feed());
        $older = $this->savedSearchWithMember($user, 'climate', $entry);
        $newer = $this->savedSearchWithMember($user, 'rocket', $entry);

        $byEntry = $this->repository()->savedSearchesByEntry([$entry->requireId()], $user->requireId());

        $pills = $byEntry[$entry->requireId()];
        self::assertSame([$newer->requireId(), $older->requireId()], array_column($pills, 'id'));
        self::assertSame($newer->getSlug(), $pills[0]['slug']);
        self::assertSame($newer->getTerm(), $pills[0]['term']);
    }

    public function testExcludesAnotherUsersSearch(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $entry = $this->entry($this->feed());
        $this->savedSearchWithMember($stranger, 'climate', $entry);

        $byEntry = $this->repository()->savedSearchesByEntry([$entry->requireId()], $owner->requireId());

        self::assertSame([], $byEntry);
    }

    public function testLoadIntoWithNoRowsReturnsEmptyList(): void
    {
        $loader = self::getContainer()->get(SavedSearchMembershipLoader::class);
        self::assertInstanceOf(SavedSearchMembershipLoader::class, $loader);

        self::assertSame([], $loader->loadInto([], $this->user('empty-loader@example.com')->requireId()));
    }

    public function testDuplicateRowsAreEnrichedWithTheirOwnMembership(): void
    {
        $user = $this->user('duplicate-loader@example.com');
        $feed = $this->feed();
        $primary = $this->entry($feed);
        $duplicate = $this->entry($feed, '-duplicate');
        $primarySearch = $this->savedSearchWithMember($user, 'climate', $primary);
        $duplicateSearch = $this->savedSearchWithMember($user, 'rocket', $duplicate);

        $duplicateRow = $this->row($duplicate);
        $primaryRow = $this->row($primary, [$duplicateRow]);

        $loader = self::getContainer()->get(SavedSearchMembershipLoader::class);
        self::assertInstanceOf(SavedSearchMembershipLoader::class, $loader);
        $enriched = $loader->loadInto([$primaryRow], $user->requireId());

        self::assertSame([$primarySearch->requireId()], array_column($enriched[0]->savedSearches, 'id'));
        self::assertSame(
            [$duplicateSearch->requireId()],
            array_column($enriched[0]->duplicates[0]->savedSearches, 'id'),
        );
    }

    private function repository(): SavedSearchEntryRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        return $repository;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/membership-loader-feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guidSuffix = ''): Entry
    {
        $entry = new Entry(
            $feed,
            'membership-loader-guid' . $guidSuffix,
            'https://example.com/membership-loader-entry' . $guidSuffix,
            'Climate report',
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            new \DateTimeImmutable('2026-07-02T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function savedSearchWithMember(User $user, string $term, Entry $entry): SavedSearch
    {
        $search = new SavedSearch($user, $term, false);
        $this->em->persist($search);
        $this->em->flush();
        $search->setSlug($search->getId() . '-' . $term);
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em->flush();

        return $search;
    }

    /** @param list<EntryListRow> $duplicates */
    private function row(Entry $entry, array $duplicates = []): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            $duplicates,
        );
    }
}

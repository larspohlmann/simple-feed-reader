<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Tests\DbTestCase;

final class EntryListRowEnricherTest extends DbTestCase
{
    public function testARowGainsItsCategoriesAndItsOwnersSavedSearches(): void
    {
        $user = new User('enricher@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $entry = $this->entry();
        $politics = new Category('politics', '');
        $this->entityManager->persist($politics);
        $this->entityManager->persist(new EntryCategory($entry, $politics, 0, 'Politics'));
        $search = new SavedSearch($user, 'climate', false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();
        $search->setSlug($search->requireId() . '-climate');
        $this->entityManager->persist(
            new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')),
        );
        $this->entityManager->flush();

        $rows = $this->enricher()->enrich([$this->row($entry)], $user->requireId());

        self::assertSame(['Politics'], $rows[0]->categories);
        self::assertSame([$search->requireId()], array_column($rows[0]->savedSearches, 'id'));
    }

    public function testNoRowsStayNoRows(): void
    {
        self::assertSame([], $this->enricher()->enrich([], 1));
    }

    private function enricher(): EntryListRowEnricher
    {
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return $enricher;
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.com/enricher-feed.xml');
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $entry = new Entry($feed, 'enricher-guid', 'https://example.com/enricher-entry', 'Climate', $now, $now);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function row(Entry $entry): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
        );
    }
}

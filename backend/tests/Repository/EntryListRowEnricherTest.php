<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\ShareEnrichment;
use App\Entity\User;
use App\Enum\ShareEnrichmentKind;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;

final class EntryListRowEnricherTest extends DbTestCase
{
    use SeedsUsers;

    private Feed $feed;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
        $this->user = $this->user('enricher@example.com');
    }

    public function testARowGainsItsCategoriesInDeclaredOrderItsSavedSearchesAndItsLinkCardsInPostOrder(): void
    {
        $entry = $this->entry('full');
        $this->category($entry, 1, 'World');
        $this->category($entry, 0, 'Politics');
        $this->card($entry, 1, 'https://b.test/');
        $this->card($entry, 0, 'https://a.test/');
        $older = $this->savedSearchWithMember('climate', $entry);
        $newer = $this->savedSearchWithMember('rocket', $entry);

        $enrichment = $this->enrich([$this->row($entry)])[0]->enrichment;

        self::assertSame(['Politics', 'World'], $enrichment->categories);
        self::assertSame([$newer->requireId(), $older->requireId()], array_column($enrichment->savedSearches, 'id'));
        self::assertSame(
            ['id' => $newer->requireId(), 'slug' => $newer->getSlug(), 'term' => 'rocket'],
            $enrichment->savedSearches[0],
        );
        self::assertSame(['https://a.test/', 'https://b.test/'], array_column($enrichment->linkCards, 'url'));
    }

    public function testEachRowAndEachDuplicateGainsOnlyItsOwnEnrichment(): void
    {
        $survivor = $this->entry('survivor');
        $duplicate = $this->entry('duplicate');
        $bare = $this->entry('bare');
        $this->category($survivor, 0, 'Politics');
        $this->category($duplicate, 0, 'Tech');
        $this->card($duplicate, 0, 'https://dup.test/');
        $search = $this->savedSearchWithMember('climate', $duplicate);

        $rows = $this->enrich([$this->row($survivor, [$this->row($duplicate)]), $this->row($bare)]);

        self::assertSame(['Politics'], $rows[0]->enrichment->categories);
        self::assertSame([], $rows[0]->enrichment->savedSearches);
        self::assertSame([], $rows[0]->enrichment->linkCards);
        $duplicateEnrichment = $rows[0]->duplicates[0]->enrichment;
        self::assertSame(['Tech'], $duplicateEnrichment->categories);
        self::assertSame([$search->requireId()], array_column($duplicateEnrichment->savedSearches, 'id'));
        self::assertSame(['https://dup.test/'], array_column($duplicateEnrichment->linkCards, 'url'));
        self::assertEquals([[], [], []], [
            $rows[1]->enrichment->categories,
            $rows[1]->enrichment->savedSearches,
            $rows[1]->enrichment->linkCards,
        ]);
    }

    public function testAnotherUsersSavedSearchIsNotShown(): void
    {
        $entry = $this->entry('foreign');
        $this->savedSearchWithMember('climate', $entry);

        $rows = $this->enricher()->enrich([$this->row($entry)], $this->user('stranger@example.com')->requireId());

        self::assertSame([], $rows[0]->enrichment->savedSearches);
    }

    public function testAPostIsPendingWhileItsEnrichmentIsNotDone(): void
    {
        $blueskyPost = $this->queued('bluesky', ShareEnrichmentKind::AppView);
        $linkPost = $this->queued('link', ShareEnrichmentKind::LinkCards);
        $done = $this->queued('done', ShareEnrichmentKind::LinkCards);
        $unqueued = $this->entry('unqueued');
        $this->entityManager->flush();
        $this->markDone($done);

        $rows = $this->enrich(array_map($this->row(...), [$blueskyPost, $linkPost, $done, $unqueued]));

        self::assertSame(
            [true, true, false, false],
            array_map(static fn (EntryListRow $row): bool => $row->enrichment->pending, $rows),
        );
    }

    public function testOnlyATopLevelRowIsPendingNeverADuplicate(): void
    {
        $survivor = $this->queued('survivor', ShareEnrichmentKind::LinkCards);
        $duplicate = $this->queued('duplicate', ShareEnrichmentKind::AppView);
        $settled = $this->entry('settled');
        $pendingDuplicate = $this->queued('pending-duplicate', ShareEnrichmentKind::AppView);

        $rows = $this->enrich([
            $this->row($survivor, [$this->row($duplicate)]),
            $this->row($settled, [$this->row($pendingDuplicate)]),
        ]);

        self::assertTrue($rows[0]->enrichment->pending);
        self::assertFalse($rows[0]->duplicates[0]->enrichment->pending);
        self::assertFalse($rows[1]->enrichment->pending);
        self::assertFalse($rows[1]->duplicates[0]->enrichment->pending);
    }

    public function testAPageCostsOneQueryPerLookup(): void
    {
        $first = $this->queued('first', ShareEnrichmentKind::LinkCards);
        $second = $this->entry('second');
        $duplicate = $this->entry('second-duplicate');
        $this->category($first, 0, 'Tech');
        $this->category($duplicate, 0, 'Science');
        $this->card($second, 0, 'https://a.test/');
        $this->savedSearchWithMember('climate', $first);
        $recorder = $this->recorder();

        $this->enrich([$this->row($first), $this->row($second, [$this->row($duplicate)])]);

        $queries = implode("\n", $recorder->queries());
        self::assertCount(4, $recorder->queries(), $queries);
        foreach (['entry_category', 'saved_search_entry', 'link_card', 'share_enrichment'] as $table) {
            self::assertCount(1, $recorder->queriesMatching($table), $table . ":\n" . $queries);
        }
    }

    public function testNoRowsStayNoRowsWithoutAQuery(): void
    {
        $recorder = $this->recorder();

        self::assertSame([], $this->enrich([]));

        self::assertSame([], $recorder->queries());
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    private function enrich(array $rows): array
    {
        return $this->enricher()->enrich($rows, $this->user->requireId());
    }

    private function enricher(): EntryListRowEnricher
    {
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return $enricher;
    }

    private function recorder(): QueryRecorder
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        return $recorder;
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $entry = new Entry($this->feed, $guid, 'https://x.test/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function category(Entry $entry, int $position, string $label): void
    {
        $category = new Category(strtolower($label), '');
        $this->entityManager->persist($category);
        $this->entityManager->persist(new EntryCategory($entry, $category, $position, $label));
        $this->entityManager->flush();
    }

    private function card(Entry $entry, int $position, string $url): void
    {
        $this->entityManager->persist(new LinkCard($entry, $position, new LinkCardContent($url, 'T', null, null, 'S')));
        $this->entityManager->flush();
    }

    private function savedSearchWithMember(string $term, Entry $entry): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();
        $search->setSlug($search->requireId() . '-' . $term);
        $this->entityManager->persist(
            new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')),
        );
        $this->entityManager->flush();

        return $search;
    }

    private function queued(string $guid, ShareEnrichmentKind $kind): Entry
    {
        $entry = $this->entry($guid);
        $this->entityManager->persist(new ShareEnrichment($entry, $kind, new \DateTimeImmutable('2026-10-10')));
        $this->entityManager->flush();

        return $entry;
    }

    private function markDone(Entry $entry): void
    {
        $enrichments = PostEnrichers::enrichments($this->entityManager);
        $claimedAt = new \DateTimeImmutable('2026-10-10');
        $enrichments->markDone($enrichments->claim($enrichments->findBy(['entry' => $entry]), $claimedAt), $claimedAt);
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

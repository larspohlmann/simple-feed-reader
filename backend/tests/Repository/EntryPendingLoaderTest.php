<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\EntryPendingLoader;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueryRecorder;

final class EntryPendingLoaderTest extends DbTestCase
{
    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/';

    private EntryPendingLoader $loader;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new EntryPendingLoader(PostEnrichers::enrichments($this->entityManager));
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
    }

    public function testNoRowsStayNoRowsWithoutAQuery(): void
    {
        $recorder = $this->recorder();

        self::assertSame([], $this->loader->loadInto([]));

        self::assertSame([], $recorder->queries());
    }

    public function testAPostIsPendingWhileItsRowIsNotDone(): void
    {
        $blueskyPost = $this->queued(self::AT_POST . 'queued', PostEnrichmentKind::AppView);
        $linkPost = $this->queued('https://mastodon.example/@a/1', PostEnrichmentKind::LinkCards);
        $done = $this->queued('https://mastodon.example/@a/2', PostEnrichmentKind::LinkCards);
        $unqueued = $this->post('https://mastodon.example/@a/3', '<p>See https://example.com/a</p>');
        $this->entityManager->flush();
        $this->markDone($done);

        $rows = $this->loader->loadInto(array_map($this->row(...), [$blueskyPost, $linkPost, $done, $unqueued]));

        self::assertSame([true, true, false, false], array_column($rows, 'pending'));
    }

    public function testOnlyTopLevelRowsAreFlaggedFromOneQuery(): void
    {
        $survivor = $this->queued('https://mastodon.example/@a/1', PostEnrichmentKind::LinkCards);
        $duplicate = $this->queued(self::AT_POST . 'duplicate', PostEnrichmentKind::AppView);
        $settled = $this->post('https://mastodon.example/@a/2', '<p>Just words.</p>');
        $pendingDuplicate = $this->queued(self::AT_POST . 'other', PostEnrichmentKind::AppView);
        $this->entityManager->flush();
        $recorder = $this->recorder();

        $rows = $this->loader->loadInto([
            $this->row($survivor, [$this->row($duplicate)]),
            $this->row($settled, [$this->row($pendingDuplicate)]),
        ]);

        self::assertTrue($rows[0]->pending);
        self::assertFalse($rows[0]->duplicates[0]->pending);
        self::assertFalse($rows[1]->pending);
        self::assertFalse($rows[1]->duplicates[0]->pending);
        self::assertCount(1, $recorder->queries(), implode("\n", $recorder->queries()));
        self::assertCount(1, $recorder->queriesMatching('post_enrichment'));
    }

    private function queued(string $guid, PostEnrichmentKind $kind): Entry
    {
        $entry = $this->post($guid, '<p>Text.</p>');
        $this->entityManager->persist(new PostEnrichment($entry, $kind, new \DateTimeImmutable('2026-10-10')));

        return $entry;
    }

    private function markDone(Entry $entry): void
    {
        $enrichments = PostEnrichers::enrichments($this->entityManager);
        $enrichments->markDone($enrichments->findBy(['entry' => $entry]), new \DateTimeImmutable('2026-10-10'));
    }

    private function post(string $guid, ?string $contentHtml, string $createdAt = '2026-10-10 12:00:00'): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($this->feed, $guid, null, 'Post', $created, $created);
        $entry->setContentHtml($contentHtml);
        $entry->markTitleDerived();
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function recorder(): QueryRecorder
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        return $recorder;
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

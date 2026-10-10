<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\PendingPostEnrichment;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\EntryPendingLoader;
use App\Repository\PendingPostEnrichmentRepository;
use App\Service\LinkCard\TriedPosts;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EntryPendingLoaderTest extends DbTestCase
{
    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/';
    private const string SHARING = '<p>Worth a read: https://example.com/story</p>';

    private TriedPosts $triedPosts;
    private EntryPendingLoader $loader;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->triedPosts = new TriedPosts(new ArrayAdapter());
        /** @var PendingPostEnrichmentRepository $pendingPosts */
        $pendingPosts = $this->entityManager->getRepository(PendingPostEnrichment::class);
        $this->loader = new EntryPendingLoader($pendingPosts, $this->triedPosts);
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
    }

    public function testNoRowsStayNoRowsWithoutAQuery(): void
    {
        $recorder = $this->recorder();

        self::assertSame([], $this->loader->loadInto([]));

        self::assertSame([], $recorder->queries());
    }

    public function testABlueskyPostWaitingForTheAppViewIsPending(): void
    {
        $queued = $this->post(self::AT_POST . 'queued', '<p>Text.</p>');
        $this->entityManager->persist(new PendingPostEnrichment($queued, new \DateTimeImmutable('2026-10-10')));
        $settled = $this->post(self::AT_POST . 'settled', '<p>See https://example.com/a</p>');
        $this->entityManager->flush();

        $rows = $this->loader->loadInto([$this->row($queued), $this->row($settled)]);

        self::assertTrue($rows[0]->pending);
        self::assertFalse($rows[1]->pending);
    }

    public function testALinkPostIsPendingUntilItWasTried(): void
    {
        $untried = $this->post('https://mastodon.example/@a/1', self::SHARING);
        $tried = $this->post('https://mastodon.example/@a/2', self::SHARING);
        $alsoTried = $this->post('https://mastodon.example/@a/3', self::SHARING);
        $this->entityManager->flush();
        $this->triedPosts->mark($tried->requireId());
        $this->triedPosts->mark($alsoTried->requireId());
        self::assertFalse($this->row($untried)->pending);

        $rows = $this->loader->loadInto([$this->row($untried), $this->row($tried), $this->row($alsoTried)]);

        self::assertSame([true, false, false], array_column($rows, 'pending'));
    }

    public function testAPostWithCardsOrWithoutASharedLinkIsNotPending(): void
    {
        $withCards = $this->post('https://mastodon.example/@a/1', self::SHARING);
        $withoutLinks = $this->post('https://mastodon.example/@a/2', '<p>Just words.</p>');
        $this->entityManager->flush();
        $card = new LinkCardContent('https://example.com/story', 'Story', null, null, 'example.com');

        $rows = $this->loader->loadInto([$this->row($withCards)->withLinkCards([$card]), $this->row($withoutLinks)]);

        self::assertFalse($rows[0]->pending);
        self::assertFalse($rows[1]->pending);
    }

    public function testAnArticleSharingALinkIsNotPending(): void
    {
        $now = new \DateTimeImmutable('2026-10-10 12:00:00');
        $article = new Entry($this->feed, 'https://example.org/a', 'https://example.org/a', 'Article', $now, $now);
        $article->setContentHtml(self::SHARING);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        self::assertFalse($this->loader->loadInto([$this->row($article)])[0]->pending);
    }

    public function testDuplicatesGetTheirOwnFlagFromOneQuery(): void
    {
        $survivor = $this->post('https://mastodon.example/@a/1', '<p>Just words.</p>');
        $duplicate = $this->post(self::AT_POST . 'duplicate', '<p>Text.</p>');
        $this->entityManager->persist(new PendingPostEnrichment($duplicate, new \DateTimeImmutable('2026-10-10')));
        $linkPost = $this->post('https://mastodon.example/@a/2', self::SHARING);
        $this->entityManager->flush();
        $recorder = $this->recorder();

        $rows = $this->loader->loadInto([
            $this->row($survivor, [$this->row($duplicate)]),
            $this->row($linkPost),
        ]);

        self::assertFalse($rows[0]->pending);
        self::assertTrue($rows[0]->duplicates[0]->pending);
        self::assertTrue($rows[1]->pending);
        self::assertCount(1, $recorder->queries(), implode("\n", $recorder->queries()));
        self::assertCount(1, $recorder->queriesMatching('pending_post_enrichment'));
    }

    private function post(string $guid, string $contentHtml): Entry
    {
        $now = new \DateTimeImmutable('2026-10-10 12:00:00');
        $entry = new Entry($this->feed, $guid, null, 'Post', $now, $now);
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

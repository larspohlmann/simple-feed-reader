<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkCardSweepTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;
    private LinkCardSweep $sweep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<html><head><title>Card for ' . $url . '</title></head></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        ));
        $this->sweep = new LinkCardSweep(
            PostEnrichers::enrichments($this->entityManager),
            ShareLinkCardsWiring::build($this->entityManager, $this->client, $this->clock),
            new NaiveUtcClock($this->clock),
        );
    }

    public function testCardsTheOldestQueuedPostsOfEveryFeedWithinItsBudget(): void
    {
        $older = $this->queuedPost('https://mastodon.test/@a.rss', 1);
        $old = $this->queuedPost('https://news.test/feed', 2);
        $newest = $this->queuedPost('https://mastodon.test/@b.rss', 3);

        $report = $this->sweep->sweep(LinkCardBudgetModel::fetches(2));

        self::assertEquals(new LinkCardSweepReportModel(2, 2), $report);
        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(
            [$newest->requireId()],
            PostEnrichers::enrichments($this->entityManager)->pendingEntryIdsAmong(
                [$older->requireId(), $old->requireId(), $newest->requireId()],
            ),
        );
    }

    public function testAnEmptyBudgetDoesNothing(): void
    {
        $this->queuedPost('https://mastodon.test/@a.rss', 1);

        $report = $this->sweep->sweep(LinkCardBudgetModel::fetches(0));

        self::assertEquals(new LinkCardSweepReportModel(0, 0), $report);
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testReportsItsCountsForTheLog(): void
    {
        self::assertSame(
            ['considered' => 3, 'completed' => 2],
            (new LinkCardSweepReportModel(3, 2))->toLogContext(),
        );
    }

    /** Queued `10 - $order` minutes ago, so a lower order is older; one shared link, in a feed of its own. */
    private function queuedPost(string $feedUrl, int $order): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = $this->clock->now();
        $entry = new Entry($feed, $feedUrl . '#' . $order, null, 'Post', $now, $now);
        $entry->setContentHtml('<p>https://example.com/' . $order . '</p>');
        $entry->markTitleDerived();
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment(
            $entry,
            ShareEnrichmentKind::LinkCards,
            $now->modify(\sprintf('-%d minutes', 10 - $order)),
        ));
        $this->entityManager->flush();

        return $entry;
    }
}

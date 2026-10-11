<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueuedLinkPosts;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class LinkCardSweepTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;
    private LinkCardSweep $sweep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = ShareLinkCardsWiring::titledPages();
        $this->sweep = LinkCardSweepWiring::build($this->entityManager, $this->client, $this->clock);
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

    /** Queued `10 - $order` minutes ago, so a lower order is older; one shared link, in a feed of its own. */
    private function queuedPost(string $feedUrl, int $order): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);

        return QueuedLinkPosts::persist(
            $this->entityManager,
            $feed,
            $feedUrl . '#' . $order,
            '<p>https://example.com/' . $order . '</p>',
            $this->clock->now()->modify(\sprintf('-%d minutes', 10 - $order)),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Service\Content\LinkCard\LinkCardDrain;
use App\Service\Content\LinkCard\Model\LinkCardDrainReportModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\DbTestCase;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueuedLinkPosts;
use App\Tests\Support\ShareLinkCardsWiring;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LinkCardDrainTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = ShareLinkCardsWiring::titledPages();
    }

    public function testCardsTheWholeQueueWhenItHoldsMoreThanOnePass(): void
    {
        $this->queuePosts(9);

        self::assertEquals(new LinkCardDrainReportModel(9, true), $this->drainWithin(150));
        self::assertSame(9, $this->client->getRequestsCount());
    }

    public function testLeavesNoCardedEntryInTheEntityManager(): void
    {
        $firstPost = $this->queuedPost(1);
        $secondPost = $this->queuedPost(2);

        $this->drainWithin(150);

        self::assertFalse($this->entityManager->contains($firstPost));
        self::assertFalse($this->entityManager->contains($secondPost));
    }

    public function testAnEmptyQueueFetchesNothing(): void
    {
        self::assertEquals(new LinkCardDrainReportModel(0, true), $this->drainWithin(150));
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testAWindowShorterThanOneFetchHasNotCaughtUp(): void
    {
        $this->queuePosts(1);

        self::assertEquals(new LinkCardDrainReportModel(0, false), $this->drainWithin(7));
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testStartsNoFetchThatCannotEndBeforeTheWindowDoes(): void
    {
        $this->queuePosts(9);
        $this->client = new MockHttpClient(function (): MockResponse {
            $this->clock->sleep(8);

            return self::titledPage();
        });

        self::assertEquals(new LinkCardDrainReportModel(2, false), $this->drainWithin(20));
        self::assertSame(2, $this->client->getRequestsCount());
    }

    public function testAPageThatFailsIsNotFetchedAgain(): void
    {
        $this->queuePosts(8);
        $this->client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        self::assertEquals(new LinkCardDrainReportModel(8, true), $this->drainWithin(150));
        self::assertSame(8, $this->client->getRequestsCount());
    }

    public function testARowAnotherWorkerHoldsIsLeftToThatWorker(): void
    {
        $this->queuePosts(1);
        $this->claimTheQueuedRowsAsAnotherWorker();

        self::assertEquals(new LinkCardDrainReportModel(0, true), $this->drainWithin(150));
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testAPassThatLosesItsRowsHasNotCaughtUp(): void
    {
        $this->queuePosts(1);
        $this->client = new MockHttpClient(function (): MockResponse {
            $this->clock->sleep(ShareEnrichment::CLAIM_LEASE_SECONDS + 1);
            $this->claimTheQueuedRowsAsAnotherWorker();

            return self::titledPage();
        });

        self::assertEquals(new LinkCardDrainReportModel(0, false), $this->drainWithin(150));
    }

    private function drainWithin(int $windowSeconds): LinkCardDrainReportModel
    {
        return new LinkCardDrain(
            LinkCardSweepWiring::build($this->entityManager, $this->client, $this->clock),
            $this->entityManager,
            $this->clock,
            $windowSeconds,
        )->drain();
    }

    private function claimTheQueuedRowsAsAnotherWorker(): void
    {
        $now = new NaiveUtcClock($this->clock)->now();
        $enrichments = PostEnrichers::enrichments($this->entityManager);
        $enrichments->claim($enrichments->findClaimableLinkCards($now, 1), $now);
    }

    private static function titledPage(): MockResponse
    {
        return new MockResponse(
            '<html lang="en"><head><title>Card</title></head></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        );
    }

    private function queuePosts(int $count): void
    {
        foreach (range(1, $count) as $order) {
            $this->queuedPost($order);
        }
    }

    /** Queued `$order` minutes before now; one shared link, in a feed of its own. */
    private function queuedPost(int $order): Entry
    {
        $feedUrl = 'https://mastodon.test/@' . $order . '.rss';
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);

        return QueuedLinkPosts::persist(
            $this->entityManager,
            $feed,
            $feedUrl . '#' . $order,
            '<p>https://example.com/' . $order . '</p>',
            $this->clock->now()->modify(\sprintf('-%d minutes', $order)),
        );
    }
}

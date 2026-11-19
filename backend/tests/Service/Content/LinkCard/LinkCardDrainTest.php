<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
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

        self::assertEquals(new LinkCardDrainReportModel(9, true), $this->drain(150)->drain());
        self::assertSame(9, $this->client->getRequestsCount());
    }

    public function testLeavesNoCardedEntryInTheEntityManager(): void
    {
        $firstPost = $this->queuedPost('https://mastodon.test/@1.rss', 1);
        $secondPost = $this->queuedPost('https://mastodon.test/@2.rss', 2);

        $this->drain(150)->drain();

        self::assertFalse($this->entityManager->contains($firstPost));
        self::assertFalse($this->entityManager->contains($secondPost));
    }

    public function testAnEmptyQueueFetchesNothing(): void
    {
        self::assertEquals(new LinkCardDrainReportModel(0, true), $this->drain(150)->drain());
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testAWindowShorterThanOneFetchHasNotCaughtUp(): void
    {
        $this->queuePosts(1);

        self::assertEquals(new LinkCardDrainReportModel(0, false), $this->drain(7)->drain());
        self::assertSame(0, $this->client->getRequestsCount());
    }

    public function testStartsNoFetchThatCannotEndBeforeTheWindowDoes(): void
    {
        $this->queuePosts(9);
        $this->client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->clock->sleep(8);

            return new MockResponse(
                '<html lang="en"><head><title>Card for ' . $url . '</title></head></html>',
                ['response_headers' => ['content-type' => 'text/html']],
            );
        });

        self::assertEquals(new LinkCardDrainReportModel(2, false), $this->drain(20)->drain());
        self::assertSame(2, $this->client->getRequestsCount());
    }

    public function testAPageThatFailsIsNotFetchedAgain(): void
    {
        $this->queuePosts(8);
        $this->client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        self::assertEquals(new LinkCardDrainReportModel(8, true), $this->drain(150)->drain());
        self::assertSame(8, $this->client->getRequestsCount());
    }

    public function testARowAnotherWorkerHoldsIsLeftToThatWorker(): void
    {
        $this->queuePosts(1);
        $now = (new NaiveUtcClock($this->clock))->now();
        $enrichments = PostEnrichers::enrichments($this->entityManager);
        $enrichments->claim($enrichments->findClaimableLinkCards($now, 1), $now);

        self::assertEquals(new LinkCardDrainReportModel(0, true), $this->drain(150)->drain());
        self::assertSame(0, $this->client->getRequestsCount());
    }

    private function drain(int $windowSeconds): LinkCardDrain
    {
        return new LinkCardDrain(
            LinkCardSweepWiring::build($this->entityManager, $this->client, $this->clock),
            $this->entityManager,
            $this->clock,
            $windowSeconds,
        );
    }

    private function queuePosts(int $count): void
    {
        foreach (range(1, $count) as $order) {
            $this->queuedPost('https://mastodon.test/@' . $order . '.rss', $order);
        }
    }

    /** Queued `$order` minutes before now; one shared link, in a feed of its own. */
    private function queuedPost(string $feedUrl, int $order): Entry
    {
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

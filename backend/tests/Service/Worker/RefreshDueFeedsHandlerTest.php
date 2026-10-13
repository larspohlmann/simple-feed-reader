<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Worker\Handler\RefreshDueFeedsHandler;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\RefreshWarmUp;
use App\Tests\DbTestCase;
use App\Tests\Support\RefreshFetchers;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubPassFetcher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/** Runs the container's real RefreshRunner with the pass and homepage fetchers swapped, to keep it off the network. */
final class RefreshDueFeedsHandlerTest extends DbTestCase
{
    use SeedsUsers;

    public function testFiringWithNoDueFeedsCompletesWithoutThrowing(): void
    {
        $this->handler()->__invoke(new RefreshDueFeeds());

        $this->addToAssertionCount(1);
    }

    public function testFiringRefreshesADueFeedAndMovesItsLastFetchedAt(): void
    {
        $feed = new Feed('https://example.com/due/feed.xml');
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($feed);
        $subscriber = $this->user('sweeper@example.com');
        $this->entityManager->persist(new Subscription($subscriber, $feed, new \DateTimeImmutable('-1 day')));
        $this->entityManager->flush();
        self::assertNull($feed->getLastFetchedAt());

        $passes = new StubPassFetcher();
        $passes->willReturn(
            $feed->getUrl(),
            FetchResponseModel::fetched($feed->getUrl(), false, $this->rss(), null, null),
        );
        RefreshFetchers::install(self::getContainer(), $passes, 'https://example.com');

        $this->handler()->__invoke(new RefreshDueFeeds());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->getRepository(Feed::class)->find($feed->getId());
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->getLastFetchedAt());
    }

    public function testAFreshWorkersFirstFiringTakesOnlyTheWarmUpBatch(): void
    {
        $subscriber = $this->user('backlog@example.com');
        $passes = new StubPassFetcher();
        foreach (range(1, 11) as $index) {
            $feed = new Feed(sprintf('https://example.com/backlog/%d.xml', $index));
            $feed->scheduleNextFetchAt(new \DateTimeImmutable('-1 hour'));
            $this->entityManager->persist($feed);
            $this->entityManager->persist(new Subscription($subscriber, $feed, new \DateTimeImmutable('-1 day')));
            $passes->willReturn($feed->getUrl(), FetchResponseModel::notModified($feed->getUrl(), false, null, null));
        }
        $this->entityManager->flush();
        RefreshFetchers::install(self::getContainer(), $passes, 'https://example.com');
        $logSpy = new TestHandler();
        $handler = new RefreshDueFeedsHandler(
            $this->refreshRunner(),
            new RefreshWarmUp(),
            new Logger('test', [$logSpy]),
        );

        $handler->__invoke(new RefreshDueFeeds());

        $report = $logSpy->getRecords()[0]->context['report'];
        self::assertIsArray($report);
        self::assertSame(10, $report['total']);
        self::assertSame(1, $report['remaining']);
    }

    /**
     * The handler's whole job past delegating to RefreshRunner is logging its
     * report -- this pins the exact shape of that log line, not just that
     * something got logged.
     */
    public function testFiringLogsTheReportUnderTheReportKey(): void
    {
        $logSpy = new TestHandler();
        $handler = new RefreshDueFeedsHandler(
            $this->refreshRunner(),
            new RefreshWarmUp(),
            new Logger('test', [$logSpy]),
        );

        $handler->__invoke(new RefreshDueFeeds());

        $records = $logSpy->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Worker refresh sweep finished.', $records[0]->message);
        self::assertSame(['report'], array_keys($records[0]->context));
        $report = $records[0]->context['report'];
        self::assertIsArray($report);
        self::assertSame(
            [
                'status', 'total', 'fetched', 'notModified', 'failed',
                'throttled', 'skippedForBudget', 'remaining', 'pruned',
            ],
            array_keys($report),
        );
    }

    private function refreshRunner(): RefreshRunner
    {
        /** @var RefreshRunner $runner */
        $runner = self::getContainer()->get(RefreshRunner::class);

        return $runner;
    }

    private function rss(): string
    {
        return /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Due</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>due-1</guid></item>
            </channel></rss>
            XML;
    }

    private function handler(): RefreshDueFeedsHandler
    {
        /** @var RefreshDueFeedsHandler $handler */
        $handler = self::getContainer()->get(RefreshDueFeedsHandler::class);

        return $handler;
    }
}

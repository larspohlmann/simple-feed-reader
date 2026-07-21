<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\AtomParser;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RefreshRunnerTest extends DbTestCase
{
    private MockClock $clock;
    private StubFeedFetcher $fetcher;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher($this->clock);
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    private function runner(?EntityManagerInterface $runnerEm = null): RefreshRunner
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);

        return new RefreshRunner(
            $feedRepository,
            $runnerEm ?? $this->em,
            $this->fetcher,
            new FeedParser(new Rss2Parser(), new AtomParser(), new Rss1Parser()),
            new EntryIngestor($this->em, $entryRepository, new EntrySanitizer(), $this->clock),
            new FeedScheduler($this->clock),
            new EntryPruner($this->em, $this->clock),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
        );
    }

    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour'));
        $this->em->persist($feed);

        return $feed;
    }

    private function rss(string $title, string $guid): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>{$title}</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>{$guid}</guid></item>
            </channel></rss>
            XML;
    }

    public function testRefreshesDueFeedsAndReports(): void
    {
        $feedA = $this->dueFeed('https://a.example.com/feed');
        $feedB = $this->dueFeed('https://b.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn(
            $feedA->getUrl(),
            FetchResponse::fetched($feedA->getUrl(), false, $this->rss('A', 'a-1'), '"etag-a"', null),
        );
        $this->fetcher->willReturn(
            $feedB->getUrl(),
            FetchResponse::notModified($feedB->getUrl(), false, null, null),
        );

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(2, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $report->remaining);

        self::assertSame('"etag-a"', $feedA->getEtag());
        self::assertSame('A', $feedA->getTitle());
        self::assertNotNull($feedA->getNextFetchAt());
        self::assertGreaterThan($this->clock->now(), $feedA->getNextFetchAt());
        self::assertCount(1, $this->em->getRepository(Entry::class)->findAll());
    }

    public function testFailedFeedIsRecordedAndOthersContinue(): void
    {
        $bad = $this->dueFeed('https://bad.example.com/feed');
        $good = $this->dueFeed('https://good.example.com/feed');
        $this->em->flush();

        $this->fetcher->willThrow($bad->getUrl(), new FeedUnreachableException('connection refused'));
        $this->fetcher->willReturn(
            $good->getUrl(),
            FetchResponse::fetched($good->getUrl(), false, $this->rss('G', 'g-1'), null, null),
        );

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Erroring, $bad->getStatus());
        self::assertSame(1, $bad->getConsecutiveFailures());
        self::assertStringContainsString('connection refused', (string) $bad->getLastErrorMessage());
    }

    public function testUnparseableBodyIsRecordedAsFailure(): void
    {
        $feed = $this->dueFeed('https://junk.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched($feed->getUrl(), false, 'this is not xml at all', null, null),
        );

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
    }

    public function testGoneFeedIsMarkedGone(): void
    {
        $feed = $this->dueFeed('https://dead.example.com/feed');
        $this->em->flush();

        $this->fetcher->willThrow($feed->getUrl(), new FeedGoneException('HTTP 410 Gone'));

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testBudgetExhaustionSkipsRemainingFeeds(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->em->flush();

        foreach ([$first, $second, $third] as $index => $feed) {
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::fetched($feed->getUrl(), false, $this->rss('F' . $index, 'g-' . $index), null, null),
            );
        }
        $this->fetcher->secondsPerFetch = 100;

        $report = $this->runner()->run(RefreshRequest::allDue(205));

        // 100 s + 100 s spent leaves 5 s — below the 10 s safety margin, so the
        // third feed is skipped and stays due for the next run.
        self::assertSame('partial', $report->status);
        self::assertSame(2, $report->fetched);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame(1, $report->remaining);
        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    /**
     * The user endpoint polls until `remaining` reaches 0. A run that processes
     * nothing leaves `remaining` unchanged, so a budget at or below the safety
     * margin would spin the client forever. Guarantee one feed of progress.
     */
    public function testBudgetSmallerThanTheSafetyMarginStillProcessesOneFeed(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $this->em->flush();

        foreach ([$first, $second] as $feed) {
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::notModified($feed->getUrl(), false, null, null),
            );
        }

        $report = $this->runner()->run(RefreshRequest::allDue(3));

        self::assertSame(1, $report->notModified);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame([$first->getUrl()], $this->fetcher->fetchedUrls);
        // Progress was made, so a polling caller converges instead of looping.
        self::assertSame(1, $report->remaining);
    }

    public function testBusyWhenLockIsHeld(): void
    {
        $lock = $this->lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire());

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('busy', $report->status);
        self::assertSame(0, $report->total);
        self::assertSame([], $this->fetcher->fetchedUrls);
        $lock->release();
    }

    public function testLockIsReleasedAfterRun(): void
    {
        $feed = $this->dueFeed('https://a.example.com/feed');
        $this->em->flush();
        $this->fetcher->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));

        $this->runner()->run(RefreshRequest::allDue(300));

        $lock = $this->lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire(), 'lock should be free after a completed run');
        $lock->release();
    }

    public function testPermanentRedirectUpdatesFeedUrl(): void
    {
        $feed = $this->dueFeed('https://old.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched('https://new.example.com/feed', true, $this->rss('Moved', 'm-1'), null, null),
        );

        $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('https://new.example.com/feed', $feed->getUrl());
    }

    public function testPermanentRedirectToAnAlreadyKnownUrlIsIgnored(): void
    {
        $existing = $this->dueFeed('https://new.example.com/feed');
        $existing->setNextFetchAt($this->clock->now()->modify('+1 day'));
        $moving = $this->dueFeed('https://old.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn(
            $moving->getUrl(),
            FetchResponse::fetched('https://new.example.com/feed', true, $this->rss('Moved', 'm-1'), null, null),
        );

        $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('https://old.example.com/feed', $moving->getUrl());
    }

    public function testUserScopedRunOnlyTouchesThatUsersFeeds(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);
        $mine = $this->dueFeed('https://mine.example.com/feed');
        $this->dueFeed('https://other.example.com/feed');
        $this->em->persist(new Subscription($user, $mine, $this->clock->now()));
        $this->em->flush();

        $this->fetcher->willReturn($mine->getUrl(), FetchResponse::notModified($mine->getUrl(), false, null, null));

        $userId = $user->getId();
        self::assertNotNull($userId);
        $report = $this->runner()->run(RefreshRequest::forUser($userId, 60));

        self::assertSame([$mine->getUrl()], $this->fetcher->fetchedUrls);
        self::assertSame(1, $report->total);
    }

    public function testAllDueRunPrunesOldEntries(): void
    {
        $feed = $this->dueFeed('https://a.example.com/feed');
        $ancient = new Entry($feed, 'ancient', null, 'Ancient', $this->clock->now()->modify('-200 days'));
        $ancient->setPublishedAt($this->clock->now()->modify('-200 days'));
        $this->em->persist($ancient);
        $this->em->flush();

        $this->fetcher->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->pruned);
    }

    public function testUserScopedRunDoesNotPrune(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);
        $feed = $this->dueFeed('https://a.example.com/feed');
        $ancient = new Entry($feed, 'ancient', null, 'Ancient', $this->clock->now()->modify('-200 days'));
        $ancient->setPublishedAt($this->clock->now()->modify('-200 days'));
        $this->em->persist($ancient);
        $this->em->persist(new Subscription($user, $feed, $this->clock->now()));
        $this->em->flush();

        $this->fetcher->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));

        $userId = $user->getId();
        self::assertNotNull($userId);
        $report = $this->runner()->run(RefreshRequest::forUser($userId, 60));

        self::assertSame(0, $report->pruned);
        self::assertCount(1, $this->em->getRepository(Entry::class)->findAll());
    }

    /**
     * A unique-constraint violation on flush rolls back AND closes the
     * EntityManager. Continuing the loop would turn one collision into
     * "EntityManager is closed" for every remaining feed, so the runner must
     * abort — and it must never touch the EM again (no countDue, no prune).
     */
    public function testEntityManagerFailureAbortsRunWithoutCascading(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->em->flush();

        foreach ([$first, $second, $third] as $index => $feed) {
            $this->fetcher->willReturn(
                $feed->getUrl(),
                FetchResponse::fetched($feed->getUrl(), false, $this->rss('F' . $index, 'g-' . $index), null, null),
            );
        }

        $flushes = 0;
        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willReturnCallback(function () use (&$flushes): void {
            $flushes++;
            if ($flushes === 2) {
                throw new UniqueConstraintViolationException(
                    new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
                    },
                    null,
                );
            }
            $this->em->flush();
        });

        $report = $this->runner($failingEm)->run(RefreshRequest::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(0, $report->pruned);
        // The failing feed plus the untouched third one are still due.
        self::assertSame(2, $report->remaining);
        // The loop stopped: the third feed was never fetched.
        self::assertSame(
            ['https://one.example.com/feed', 'https://two.example.com/feed'],
            $this->fetcher->fetchedUrls,
        );
    }

    public function testLockIsReleasedAfterAnAbortedRun(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $this->em->flush();
        $this->fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched($feed->getUrl(), false, $this->rss('F', 'g-1'), null, null),
        );

        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willThrowException(new UniqueConstraintViolationException(
            new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
            },
            null,
        ));

        $report = $this->runner($failingEm)->run(RefreshRequest::allDue(300));

        self::assertSame('aborted', $report->status);
        $lock = $this->lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire(), 'lock should be free after an aborted run');
        $lock->release();
    }
}

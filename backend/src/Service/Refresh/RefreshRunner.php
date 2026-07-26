<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\FeedScheduler;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\Exception\FeedParseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh implementation behind all three callers (CLI, maintenance
 * endpoint, user endpoint). Globally lock-guarded, budget-bound, flushes per
 * feed so a budget exit never loses committed work.
 *
 * Feeds are fetched concurrently, but everything that touches Doctrine — parse,
 * ingest, flush — happens serially as each outcome arrives, so persistence
 * semantics are unchanged from the one-feed-at-a-time original.
 *
 * The eleven constructor collaborators are deliberate: the runner is the
 * refresh pipeline's composition root, and each one is a seam the tests swap
 * independently (fetcher, body parser, ingestor, scheduler, …). Bagging them
 * into a parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RefreshRunner
{
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 300.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;
    private const int ETAG_MAX = 512;
    private const int LAST_MODIFIED_MAX = 255;
    private const int URL_MAX = 750;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly EntityManagerInterface $em,
        private readonly BatchFeedFetcherInterface $fetcher,
        private readonly FeedBodyParser $bodyParser,
        private readonly EntryIngestor $ingestor,
        private readonly FaviconResolver $faviconResolver,
        private readonly FeedScheduler $scheduler,
        private readonly EntryPruner $pruner,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(RefreshRequest $request): RefreshReport
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReport::busy();
        }

        try {
            return $this->refresh($request);
        } finally {
            $lock->release();
        }
    }

    private function refresh(RefreshRequest $request): RefreshReport
    {
        $now = $this->clock->now();
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        $feeds = $this->feedRepository->findDue(
            $now,
            self::BATCH_LIMIT,
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );

        // The deadline gates when a fetch may *start*, not when it must finish —
        // nothing cancels a fetch already in flight. The real per-response bound
        // is `max_duration` (20 s) on each request, so a run can overrun this
        // budget by up to 20 s, or a multiple of it on a pathological redirect
        // chain. The serial fetcher had the identical per-feed bound, so this is
        // not a regression; it is easy to mistake `budgetSeconds` for a hard
        // ceiling, so it is spelled out here rather than left to be rediscovered.
        $queue = new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds);
        $tally = $this->processOutcomes($feeds, $queue);

        if ($tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no
            // prune. Everything unprocessed stays due for the next run.
            return RefreshReport::aborted(
                \count($feeds),
                $tally->fetched,
                $tally->notModified,
                $tally->failed,
                \count($feeds) - $tally->processed,
            );
        }

        return $this->resolveFaviconsAndReport($request, $feeds, $tally, $queue, $cooldownCutoff);
    }

    /**
     * Resolves favicons, then assembles the completed report. Split out so the
     * favicon flush's own failure mode is visible next to the code that
     * handles it: every feed's fetch outcome has already been flushed
     * individually, so nothing already persisted is at risk here — but the
     * EntityManager can still close under this flush exactly as it can under
     * a fetch's, and RefreshController's contract promises the client a JSON
     * report with a `status` field, never an opaque 500 its poll loop has no
     * branch for.
     *
     * @param list<Feed> $feeds
     */
    private function resolveFaviconsAndReport(
        RefreshRequest $request,
        array $feeds,
        RefreshTally $tally,
        BudgetedFeedQueue $queue,
        ?\DateTimeImmutable $cooldownCutoff,
    ): RefreshReport {
        try {
            $this->resolveMissingFavicons($tally->faviconEligibleFeeds);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $e],
            );

            return RefreshReport::aborted(
                \count($feeds),
                $tally->fetched,
                $tally->notModified,
                $tally->failed,
                $queue->skippedCount(),
            );
        }

        return RefreshReport::finished(
            \count($feeds),
            $tally->fetched,
            $tally->notModified,
            $tally->failed,
            $queue->skippedCount(),
            $this->countRemaining($request, $cooldownCutoff, $queue->skippedCount()),
            $request->prune ? $this->pruner->prune() : 0,
        );
    }

    /**
     * Drives the concurrent fetch and applies each result serially as it lands.
     * Breaking out of the loop cancels whatever is still in flight.
     *
     * @param list<Feed> $feeds
     */
    private function processOutcomes(array $feeds, BudgetedFeedQueue $queue): RefreshTally
    {
        $byId = [];
        foreach ($feeds as $feed) {
            $byId[(int) $feed->getId()] = $feed;
        }

        $tally = new RefreshTally();

        foreach ($this->fetcher->fetchAll($queue->tickets()) as $feedId => $outcome) {
            $feed = $byId[$feedId];
            $outcomeKind = $this->applyOutcome($feed, $outcome);
            $tally->record($outcomeKind, $feed);

            if (FeedOutcome::Aborted === $outcomeKind) {
                break;
            }
        }

        return $tally;
    }

    private function applyOutcome(Feed $feed, FetchOutcome $outcome): FeedOutcome
    {
        try {
            return $this->persistOutcome($feed, $outcome);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            // A failed flush rolls back AND closes the EntityManager, so every
            // later persist/flush would throw "EntityManager is closed".
            // Stop here instead of cascading the failure across the batch.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedOutcome::Aborted;
        }
    }

    /**
     * A single-feed scope matches on id alone — countDue ignores the schedule and
     * would keep answering 1 even after a successful refresh, so a polling caller
     * would never see `remaining` reach 0.
     */
    private function countRemaining(RefreshRequest $request, ?\DateTimeImmutable $cooldownCutoff, int $skipped): int
    {
        if (null !== $request->feedId) {
            return $skipped;
        }

        return $this->feedRepository->countDue(
            $this->clock->now(),
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );
    }

    private function persistOutcome(Feed $feed, FetchOutcome $outcome): FeedOutcome
    {
        try {
            $response = $outcome->responseOrThrow();

            if ($response->notModified) {
                // A feed can be permanently moved AND answer 304 at the new
                // location; without this the redirect chain is re-walked on
                // every single refresh, forever.
                $this->applyPermanentRedirect($feed, $response);
                $this->scheduler->recordSuccess($feed, 0);
                $this->em->flush();

                return FeedOutcome::NotModified;
            }

            $body = $response->body;
            if (null === $body) {
                // Not reachable via the FetchResponse factories, but parsing an
                // empty string would silently record a bogus "successful" fetch.
                throw new FeedParseException('Fetcher returned a modified response without a body.');
            }

            $parsed = $this->bodyParser->parse($feed, $body);
            $created = $this->ingestor->ingest($feed, $parsed);

            $feed->setEtag($this->truncate($response->etag, self::ETAG_MAX));
            $feed->setLastModified($this->truncate($response->lastModified, self::LAST_MODIFIED_MAX));
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, $created);
            $this->em->flush();

            return FeedOutcome::Fetched;
        } catch (FeedGoneException $e) {
            $this->scheduler->recordGone($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        } catch (FetchException | FeedParseException $e) {
            $this->scheduler->recordFailure($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        }
    }

    /**
     * Resolve and store a favicon for each favicon-eligible feed that still
     * lacks one, fetching every homepage in one concurrent batch. $feeds is
     * `RefreshTally::$faviconEligibleFeeds`, not the full due-feed list and
     * not every processed feed: a feed the budget deferred never started a
     * fetch (it gets its favicon on the pass that actually fetches it), and a
     * feed whose own fetch just failed has no new content to show an icon
     * beside, so it is excluded too rather than paying a homepage round trip
     * on every sweep for a feed that may never recover.
     *
     * @param list<Feed> $feeds
     */
    private function resolveMissingFavicons(array $feeds): void
    {
        $baseUrls = [];
        foreach ($feeds as $feed) {
            if (null !== $feed->getFaviconUrl()) {
                continue;
            }
            $baseUrls[(int) $feed->getId()] = $feed->getSiteUrl() ?? $feed->getUrl();
        }

        if ([] === $baseUrls) {
            return;
        }

        $icons = $this->faviconResolver->resolveAll($baseUrls);
        foreach ($feeds as $feed) {
            $icon = $icons[(int) $feed->getId()] ?? null;
            if (null !== $icon) {
                $feed->setFaviconUrl($icon);
            }
        }

        $this->em->flush();
    }

    private function applyPermanentRedirect(Feed $feed, FetchResponse $response): void
    {
        if (!$response->permanentRedirect || $response->finalUrl === $feed->getUrl()) {
            return;
        }
        // A truncated URL is a broken URL, so an over-long target is declined
        // rather than shortened; the feed keeps working at its current address.
        if (mb_strlen($response->finalUrl) > self::URL_MAX) {
            return;
        }
        // Only adopt the new URL if no other feed already claims it (unique index).
        if ($this->feedRepository->findOneBy(['url' => $response->finalUrl]) !== null) {
            return;
        }
        $feed->setUrl($response->finalUrl);
    }

    /**
     * ETag and Last-Modified are remote-controlled and go into length-limited
     * columns. SQLite ignores the limit, MySQL in strict mode rejects the row —
     * which would fail the flush, abort the run, and skip every queued feed.
     */
    private function truncate(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}

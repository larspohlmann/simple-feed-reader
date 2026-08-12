<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\FeedScheduler;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Parser\Exception\FeedParseException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
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
 * The twelve constructor collaborators are deliberate: the runner is the
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
        private readonly OrphanedFeedReclaimer $orphanedFeeds,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param RefreshRequest $request
     *
     * @return RefreshReport
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \DateMalformedStringException
     */
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

    /**
     * @param RefreshRequest $request
     *
     * @return RefreshReport
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \DateMalformedStringException
     */
    private function refresh(RefreshRequest $request): RefreshReport
    {
        $this->sweepOrphanedFeeds($request);

        $now = $this->clock->now();
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        $criteria = new DueFeedCriteria(
            $now,
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );

        $feeds = $this->feedRepository->findDue($criteria, self::BATCH_LIMIT);

        // The deadline gates when a fetch may *start*, not when it must finish —
        // nothing cancels a fetch already in flight. The real per-response bound
        // is `max_duration` (20 s) on each request, so a run can overrun this
        // budget by up to 20 s, or a multiple of it on a pathological redirect
        // chain. The serial fetcher had the identical per-feed bound, so this is
        // not a regression; it is easy to mistake `budgetSeconds` for a hard
        // ceiling, so it is spelled out here rather than left to be rediscovered.
        $queue = new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds);
        $tally = $this->processOutcomes($feeds, $queue, $now);

        if ($tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no
            // prune. Everything unprocessed stays due for the next run.
            return RefreshReport::aborted(
                \count($feeds),
                $tally->fetched,
                $tally->notModified,
                $tally->failed,
                $tally->throttled,
                \count($feeds) - $tally->processed,
            );
        }

        return $this->resolveFaviconsAndReport($request, $feeds, $tally, $queue, $criteria);
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
     *
     * @throws \DateMalformedStringException
     */
    private function resolveFaviconsAndReport(
        RefreshRequest $request,
        array $feeds,
        RefreshTally $tally,
        BudgetedFeedQueue $queue,
        DueFeedCriteria $criteria,
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
                $tally->throttled,
                $queue->skippedCount(),
            );
        }

        return RefreshReport::finished(
            \count($feeds),
            $tally->fetched,
            $tally->notModified,
            $tally->failed,
            $tally->throttled,
            $queue->skippedCount(),
            $this->countRemaining($criteria, $queue),
            $request->prune ? $this->pruner->prune() : 0,
        );
    }

    /**
     * Drives the concurrent fetch and applies each result serially as it lands.
     * Breaking out of the loop cancels whatever is still in flight.
     *
     * @param list<Feed>        $feeds
     * @param BudgetedFeedQueue $queue
     *
     * @return RefreshTally
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \DateMalformedStringException
     */
    private function processOutcomes(array $feeds, BudgetedFeedQueue $queue, \DateTimeImmutable $now): RefreshTally
    {
        $byId = [];
        foreach ($feeds as $feed) {
            $byId[(int) $feed->getId()] = $feed;
        }

        $tally = new RefreshTally();

        foreach ($this->fetcher->fetchAll($queue->tickets()) as $feedId => $outcome) {
            $feed = $byId[$feedId];
            $outcomeKind = $this->applyOutcome($feed, $outcome, $now);
            $tally->record($outcomeKind, $feed);

            if (FeedOutcome::Aborted === $outcomeKind) {
                break;
            }
        }

        return $tally;
    }

    /**
     * @throws \DateMalformedStringException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function applyOutcome(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedOutcome
    {
        try {
            return $this->persistOutcome($feed, $outcome, $now);
        } catch (UniqueConstraintViolationException | ForeignKeyConstraintViolationException | ORMException $e) {
            // A failed flush rolls back AND closes the EntityManager, so every
            // later persist/flush would throw "EntityManager is closed".
            // Stop here instead of cascading the failure across the batch.
            //
            // ForeignKeyConstraintViolationException is reachable here since
            // #246: the feed row backing an in-flight fetch can vanish
            // mid-run (unsubscribe -> OrphanedFeedReclaimer::reclaim() holds
            // no lock), and the ensuing UPDATE/INSERT against the gone row
            // throws this rather than UniqueConstraintViolationException.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedOutcome::Aborted;
        }
    }

    // Before findDue(), not after: a feed nobody subscribes to must not cost the
    // run an HTTP request. Gated on the same flag as the entry prune, so only the
    // maintenance refresh sweeps — a user-triggered refresh stays fast.
    private function sweepOrphanedFeeds(RefreshRequest $request): void
    {
        if (!$request->prune) {
            return;
        }

        $reclaimed = $this->orphanedFeeds->reclaimAll();
        if ($reclaimed > 0) {
            $this->logger->info('Reclaimed orphaned feeds', ['count' => $reclaimed]);
        }
    }

    /**
     * What this run left undone, which is what the client polls on.
     *
     * The feeds the run took on are excluded by id rather than trusted to fall
     * out of the due query on their own. A feed drops out of that query by
     * having a fetch time written, and a 429 writes none on purpose (#290) — so
     * without the exclusion a rationed feed stays `remaining` for ever, the
     * report stays `partial`, and the client re-polls without pause. In
     * production that sent 89 requests to one Reddit feed (#302).
     *
     * The exclusion also settles the single-feed scope, which used to need a
     * branch of its own: that scope matches on id alone, so countDue ignored the
     * schedule and kept answering 1 after a successful refresh. Excluded, the
     * one feed leaves the count exactly as every other handled feed does.
     */
    private function countRemaining(DueFeedCriteria $criteria, BudgetedFeedQueue $queue): int
    {
        return $this->feedRepository->countDue($criteria->excluding($queue->startedFeedIds()));
    }

    /**
     * @throws \DateMalformedStringException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    private function persistOutcome(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedOutcome
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
            $created = $this->ingestor->ingest($feed, $parsed, $now);
            // Opportunistically fill images onto entries stored before the image
            // column existed (#148). The count is discarded on purpose: the
            // refresh's success signal is NEW content, and a backfilled image is
            // not new content. The caller's flush below covers both writes.
            $this->ingestor->fillMissingImages($feed, $parsed);

            $feed->setEtag($this->truncate($response->etag, self::ETAG_MAX));
            $feed->setLastModified($this->truncate($response->lastModified, self::LAST_MODIFIED_MAX));
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, $created);
            $this->em->flush();

            return FeedOutcome::Fetched;
        } catch (FeedThrottledException $e) {
            $this->scheduler->recordThrottled($feed, $e->retryAfterSeconds);
            $this->em->flush();
            $this->logger->info('Feed rate limited: {url}', ['url' => $feed->getUrl()]);

            return FeedOutcome::Throttled;
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

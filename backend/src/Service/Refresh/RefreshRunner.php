<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Service\FeedScheduler;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Retention\EntryPruner;
use App\Service\Search\EntryIndexer;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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
 * The thirteen constructor collaborators are deliberate: the runner is the
 * refresh pipeline's composition root, and each one is a seam the tests swap
 * independently (fetcher, body parser, ingestor, scheduler, …). Bagging them
 * into a parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class RefreshRunner implements RefreshRunnerInterface
{
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;
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
        private readonly EntryIndexer $indexer,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ContentChangeMarkerInterface $changeMarker,
    ) {
    }

    /**
     * @param RefreshRequest $request
     *
     * @return RefreshReport
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

        // The deadline gates when a fetch may *start*, not finish; nothing cancels
        // one in flight. The real per-response bound is `max_duration` (20 s) per
        // request, so a run can overrun this budget by up to 20 s, or a multiple on
        // a pathological redirect chain. The serial fetcher had the identical
        // bound, so this is not a regression -- but `budgetSeconds` is no ceiling.
        $pass = new RefreshPass(
            $feeds,
            new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds),
        );
        $this->processOutcomes($pass, $now);

        // The point of the poll's static marker: move it only when a real import
        // stored new content, so a tick that finds it unmoved does no PHP work
        // (#720). An all-NotModified sweep must leave it. Checked before the abort
        // branch on purpose: entries created before an abort were already committed.
        if ($pass->tally->entriesCreated() > 0) {
            $this->changeMarker->markChanged();
        }

        if ($pass->tally->isAborted()) {
            // The EntityManager is likely closed: no favicons, no countDue, no prune.
            return $pass->abortedDuringOutcomes();
        }

        return $this->resolveFaviconsAndReport($request, $pass, $criteria);
    }

    /** @throws \DateMalformedStringException */
    private function resolveFaviconsAndReport(
        RefreshRequest $request,
        RefreshPass $pass,
        DueFeedCriteria $criteria,
    ): RefreshReport {
        try {
            $this->resolveMissingFavicons($pass->tally->faviconEligibleFeeds());
        } catch (UniqueConstraintViolationException | ORMException $e) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $e],
            );

            return $pass->abortedAfterOutcomes();
        }

        return $pass->finished(
            $this->countRemaining($criteria, $pass),
            $request->prune ? $this->pruner->prune() : 0,
        );
    }

    /** @throws \DateMalformedStringException */
    private function processOutcomes(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->applyOutcome($feed, $outcome, $now), $feed);
            if ($pass->tally->isAborted()) {
                break;
            }
        }
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function applyOutcome(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedRefreshResult
    {
        // Built here, not inside persistOutcome(): reading lastSuccessfulFetchAt
        // is only safe before recordSuccess() stamps the new one, and building
        // the context at the call site — rather than threading $now through it
        // — keeps $now from becoming tramp data phptramp would flag.
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        try {
            return $this->persistOutcome($feed, $outcome, $context);
        } catch (UniqueConstraintViolationException | ForeignKeyConstraintViolationException | ORMException $e) {
            // A failed flush rolls back AND closes the EntityManager, so every
            // later persist/flush throws "EntityManager is closed". Stop here
            // rather than cascade the failure across the batch.
            // ForeignKeyConstraintViolationException is reachable since #246: the
            // feed row behind an in-flight fetch can vanish mid-run (unsubscribe
            // -> OrphanedFeedReclaimer::reclaim() holds no lock), so the write
            // throws it, not UniqueConstraintViolationException.
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedRefreshResult::of(FeedOutcome::Aborted);
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

    // Started feeds are excluded by id: a 429 writes no fetch time (#290), so the due query alone would count a
    // rationed feed as remaining forever and keep the client polling (#302).
    private function countRemaining(DueFeedCriteria $criteria, RefreshPass $pass): int
    {
        return $this->feedRepository->countDue($criteria->excluding($pass->startedFeedIds()));
    }

    private function persistOutcome(Feed $feed, FetchOutcome $outcome, FeedIngestContext $context): FeedRefreshResult
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

                return FeedRefreshResult::of(FeedOutcome::NotModified);
            }

            $parsed = $this->bodyParser->parse($feed, $response->modifiedBody());
            $createdEntries = $this->ingestor->ingest($feed, $parsed, $context);
            // Opportunistically fill images onto entries stored before the image
            // column existed (#148). The count is discarded on purpose: the
            // refresh's success signal is NEW content, and a backfilled image is
            // not new content. The caller's flush below covers both writes.
            $this->ingestor->fillMissingImages($feed, $parsed);

            $feed->recordCacheValidators($response->etag, $response->lastModified);
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, \count($createdEntries));
            $this->em->flush();
            // Indexing needs the ids the flush just assigned, so it can only
            // happen after it — see EntryIndexer's class docblock for why a
            // slow or unreachable engine here never turns into a failed refresh.
            $this->indexer->index($createdEntries);

            return FeedRefreshResult::fetched(\count($createdEntries));
        } catch (FeedThrottledException $e) {
            $this->scheduler->recordThrottled($feed, $e->retryAfterSeconds);
            $this->em->flush();
            $this->logger->info('Feed rate limited: {url}', ['url' => $feed->getUrl()]);

            return FeedRefreshResult::of(FeedOutcome::Throttled);
        } catch (FeedGoneException $e) {
            $this->scheduler->recordGone($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedRefreshResult::of(FeedOutcome::Failed);
        } catch (FetchException | FeedParseException $e) {
            $this->scheduler->recordFailure($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedRefreshResult::of(FeedOutcome::Failed);
        }
    }

    /**
     * Resolve and store a favicon for each favicon-eligible feed that still lacks
     * one, fetching every homepage in one concurrent batch. $feeds is
     * `RefreshTally::$faviconEligibleFeeds`: not the full due-feed list, since a
     * budget-deferred feed gets its favicon on the pass that fetches it, and a
     * feed whose fetch just failed is excluded too rather than paying a homepage
     * round trip every sweep for a feed that may never recover.
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
            $baseUrls[$feed->requireId()] = $feed->getSiteUrl() ?? $feed->getUrl();
        }

        if ([] === $baseUrls) {
            return;
        }

        $icons = $this->faviconResolver->resolveAll($baseUrls);
        foreach ($feeds as $feed) {
            $icon = $icons[$feed->requireId()] ?? null;
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
}

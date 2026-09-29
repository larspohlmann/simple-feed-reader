<?php

declare(strict_types=1);

namespace App\Service\Refresh\RefreshRunner;

use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\MissingFaviconResolver;
use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\RefreshHousekeeping;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh behind the CLI, the maintenance endpoint and the user endpoint: lock-guarded and budget-bound.
 * Feeds are fetched concurrently, but each outcome is persisted and flushed serially as it lands.
 */
final readonly class RefreshRunner implements RefreshRunnerInterface
{
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;

    public function __construct(
        private FeedRepository $feedRepository,
        private BatchFeedFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private MissingFaviconResolver $missingFavicons,
        private RefreshHousekeeping $housekeeping,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ContentChangeMarkerInterface $changeMarker,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function run(RefreshRequestModel $request): RefreshReportModel
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReportModel::busy();
        }

        try {
            return $this->refresh($request);
        } finally {
            $lock->release();
        }
    }

    /** @throws \DateMalformedStringException */
    private function refresh(RefreshRequestModel $request): RefreshReportModel
    {
        // Before the due query: a feed nobody subscribes to must not cost the run an HTTP request.
        $this->housekeeping->reclaimOrphanedFeeds($request);

        $now = $this->clock->now();
        $criteria = $this->dueCriteria($request, $now);
        $feeds = $this->feedRepository->findDue($criteria, self::BATCH_LIMIT);
        // The deadline gates when a fetch may start, not finish: a request's own 20 s max_duration can overrun it.
        $pass = new RefreshPass($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds);
        $this->persistOutcomes($pass, $now);

        // Before the abort branch: what an aborted run created before the failure is already committed (#720).
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
    private function dueCriteria(RefreshRequestModel $request, \DateTimeImmutable $now): DueFeedCriteria
    {
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        return new DueFeedCriteria(
            $now,
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );
    }

    /** @throws \DateMalformedStringException */
    private function persistOutcomes(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->outcomePersister->persist($feed, $outcome, $now), $feed);
            if ($pass->tally->isAborted()) {
                break;
            }
        }
    }

    private function resolveFaviconsAndReport(
        RefreshRequestModel $request,
        RefreshPass $pass,
        DueFeedCriteria $criteria,
    ): RefreshReportModel {
        try {
            $this->missingFavicons->resolveFor($pass->tally->faviconEligibleFeeds());
        } catch (UniqueConstraintViolationException | ORMException $e) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $e],
            );

            return $pass->abortedAfterOutcomes();
        }

        return $pass->finished(
            $this->countRemaining($criteria, $pass),
            $this->housekeeping->pruneEntries($request),
        );
    }

    // Started feeds are excluded by id: a 429 writes no fetch time (#290), so the due query alone would count a
    // rationed feed as remaining forever and keep the client polling (#302).
    private function countRemaining(DueFeedCriteria $criteria, RefreshPass $pass): int
    {
        return $this->feedRepository->countDue($criteria->excluding($pass->startedFeedIds()));
    }
}

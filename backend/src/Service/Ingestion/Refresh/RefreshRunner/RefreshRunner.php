<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\RefreshRunner;

use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Service\Ingestion\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;
use App\Service\Ingestion\Refresh\MissingFaviconResolver;
use App\Service\Ingestion\Refresh\Model\RefreshReportModel;
use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;
use App\Service\Ingestion\Refresh\Pass\FeedClaims;
use App\Service\Ingestion\Refresh\Pass\RefreshPass;
use App\Service\Ingestion\Refresh\Pass\SweepLockKeepalive;
use App\Service\Ingestion\Refresh\RefreshHousekeeping;
use App\Service\Ingestion\Refresh\RefreshPassRecorder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh behind the CLI, the maintenance endpoint and the user endpoint: a sweep takes the run lock, every
 * pass claims its feeds, and each is budget-bound.
 * Feeds are fetched concurrently, but each outcome is persisted and flushed serially as it lands.
 */
final readonly class RefreshRunner implements RefreshRunnerInterface
{
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int COOLDOWN_MINUTES = 5;

    public function __construct(
        private FeedRepository $feeds,
        private RefreshPassRecorder $passRecorder,
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
        $now = $this->clock->now();
        if (!$request->needsSweepLock()) {
            return $this->refresh($request, $now, SweepLockKeepalive::withoutLock());
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReportModel::busy();
        }

        try {
            $keepalive = SweepLockKeepalive::holding($lock, $this->clock, $request->deadlineFrom($now), $this->logger);

            return $this->refresh($request, $now, $keepalive);
        } finally {
            $lock->release();
        }
    }

    /** @throws \DateMalformedStringException */
    private function refresh(
        RefreshRequestModel $request,
        \DateTimeImmutable $now,
        SweepLockKeepalive $keepalive,
    ): RefreshReportModel {
        $this->housekeeping->reclaimOrphanedFeeds($request);

        $criteria = $this->dueCriteria($request, $now);
        $feeds = $this->feeds->findDue($criteria, $request->batchLimit);
        // The deadline gates when a fetch may start, not finish: a request's own 20 s max_duration can overrun it.
        $pass = new RefreshPass(
            $feeds,
            $this->clock,
            $request->deadlineFrom($now),
            new FeedClaims($this->lockFactory),
        );

        try {
            $this->passRecorder->record($pass, $now, $keepalive);
            if ($this->isHeldElsewhere($pass)) {
                return RefreshReportModel::busy();
            }

            // Before the abort branch: what an aborted run created before the failure is already committed.
            if ($pass->tally->entriesCreated() > 0) {
                $this->changeMarker->markChanged();
            }

            if ($pass->tally->isAborted()) {
                // The EntityManager is likely closed: no favicons, no countDue, no prune.
                return $pass->abortedDuringOutcomes();
            }

            return $this->resolveFaviconsAndReport($request, $pass, $criteria, $keepalive);
        } finally {
            $pass->releaseAllFeeds();
        }
    }

    private function isHeldElsewhere(RefreshPass $pass): bool
    {
        return [] === $pass->startedFeedIds() && [] !== $pass->heldFeedIds();
    }

    /** @throws \DateMalformedStringException */
    private function dueCriteria(RefreshRequestModel $request, \DateTimeImmutable $now): DueFeedCriteria
    {
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        return new DueFeedCriteria($now, $request->target, $request->userId, $request->force, $cooldownCutoff);
    }

    private function resolveFaviconsAndReport(
        RefreshRequestModel $request,
        RefreshPass $pass,
        DueFeedCriteria $criteria,
        SweepLockKeepalive $keepalive,
    ): RefreshReportModel {
        try {
            $this->missingFavicons->resolveFor($pass->tally->faviconEligibleFeeds());
        } catch (UniqueConstraintViolationException | ORMException $exception) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $exception],
            );

            return $pass->abortedAfterOutcomes();
        }

        return $pass->finished($this->countRemaining($criteria, $pass), $this->keepHouse($request, $keepalive));
    }

    private function keepHouse(RefreshRequestModel $request, SweepLockKeepalive $keepalive): int
    {
        $keepalive->beat();
        if (!$keepalive->isHoldingTheLock()) {
            return 0;
        }

        // Before the prune: a day is recorded while every entry it counts still exists.
        $this->housekeeping->recordSubscriptionHistory($request);
        $keepalive->beat();

        return $keepalive->isHoldingTheLock() ? $this->housekeeping->pruneEntries($request) : 0;
    }

    // Started feeds are excluded by id: a 429 writes no fetch time, so the due query alone would count a rationed
    // feed as remaining forever and keep the client polling (#302).
    private function countRemaining(DueFeedCriteria $criteria, RefreshPass $pass): int
    {
        return $this->feeds->countDue($criteria->excluding($pass->startedFeedIds()));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use OpenTelemetry\API\Instrumentation\WithSpan;
use Psr\Log\LoggerInterface;

/**
 * The poll's side of the arbitration: while any driver's heartbeat is fresh, a poll only reports; otherwise it ticks.
 * A busy tick with no heartbeat behind it is flagged waitingForLock and logged, which two healthy races also reach:
 * docs/recommendations-runs.md#poll-arbitration
 */
final readonly class RecommendationPollDriver
{
    public function __construct(
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
        private WorkerPresence $presence,
        private LoggerInterface $logger,
    ) {
    }

    public function poll(User $user): RecommendationRunReportModel
    {
        if ($this->presence->isAnybodyDrivingRecommendationRuns()) {
            return $this->latestReport($user)->inBackground();
        }

        $report = $this->advancer->advance($user, TickDriver::Poll);

        // Busy is not an error: another tick holds the lock, and an error would stop the client watching a healthy run.
        if (RecommendationRunReportModel::STATUS_BUSY !== $report->status) {
            return $report;
        }

        $this->logLockWithNoHeartbeatBehindIt($user);

        return $this->latestReport($user)->inBackground()->waitingForLock();
    }

    #[WithSpan]
    public function current(User $user): RecommendationRunReportModel
    {
        $report = $this->latestReport($user);

        return $this->presence->isAnybodyDrivingRecommendationRuns() ? $report->inBackground() : $report;
    }

    /**
     * Logged every time, no debounce: a gone holder going unrecorded is what hid #439. The lock name is the operator's
     * handle, the row to delete once its holder is provably dead.
     */
    private function logLockWithNoHeartbeatBehindIt(User $user): void
    {
        $this->logger->warning('Recommendation run lock is held with no driver heartbeat behind it', [
            'lock' => RecommendationRunAdvancer::lockNameFor($user),
        ]);
    }

    private function latestReport(User $user): RecommendationRunReportModel
    {
        $latest = $this->runs->findLatestForUser($user);

        return null === $latest ? RecommendationRunReportModel::none() : RecommendationRunReportModel::fromRun($latest);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Exception\RecommendationTickLockLostException;
use App\Service\Recommendation\Run\Factory\TickContextFactory;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The driver-agnostic tick: the worker, the poll endpoint and the cron sweep all call advance(), one tick per account
 * at a time behind UserTickLock. TickPhases decides what the tick does.
 */
final readonly class RecommendationRunAdvancer
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private TickContextFactory $tickContexts,
        private TickPhases $phases,
        private UserTickLock $tickLock,
        private TickLockTtl $lockTtl,
    ) {
    }

    public function advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReportModel
    {
        $held = $this->tickLock->acquire($user, $this->lockTtl->secondsFor($user));
        if (null === $held) {
            return RecommendationRunReportModel::busy();
        }

        try {
            return $this->tick($user, $driver);
        } finally {
            $held->release();
        }
    }

    private function tick(User $user, TickDriver $driver): RecommendationRunReportModel
    {
        $run = $this->runs->findActiveForUser($user);

        if (null === $run) {
            $latest = $this->runs->findLatestForUser($user);

            return null === $latest
                ? RecommendationRunReportModel::none()
                : RecommendationRunReportModel::fromRun($latest);
        }

        try {
            return $this->phases->advance($this->tickContexts->create($run, $driver));
        } catch (RecommendationRunCancelledException | RecommendationTickLockLostException) {
            // Stopped by the user or by a lost lock: drop this tick's work, re-read the row its owner wrote.
            $this->entityManager->refresh($run);

            return RecommendationRunReportModel::fromRun($run);
        } catch (AiNotConfiguredException | AiKeyUnreadableException $exception) {
            // Such a run can never advance again, so it fails here for every driver, and the error still propagates
            // to the HTTP mapping and the worker's fault floor.
            $this->failPermanently($run, self::failureMessageFor($exception));

            throw $exception;
        }
    }

    private function failPermanently(RecommendationRun $run, string $message): void
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();
    }

    private static function failureMessageFor(AiNotConfiguredException | AiKeyUnreadableException $exception): string
    {
        return $exception instanceof AiKeyUnreadableException
            ? 'The stored API key can no longer be read.'
            : 'The AI provider is no longer configured.';
    }
}

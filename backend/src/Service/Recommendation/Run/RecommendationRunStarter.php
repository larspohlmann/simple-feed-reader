<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Creates or resumes the run a tick will advance; an already-active run is returned as-is, so a second click opens no
 * duplicate. start() always begins fresh; resume() keeps the frozen batch plan and the winners banked so far.
 */
final readonly class RecommendationRunStarter
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private AiProviderConfigurator $configurator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RecommendationRunLogRepository $logs,
    ) {
    }

    /**
     * @throws AiNotConfiguredException
     */
    public function start(User $user): RecommendationRunReportModel
    {
        if (!AiReadiness::of($this->configurator->settingsFor($user))) {
            throw new AiNotConfiguredException('This account has no AI model chosen yet.');
        }

        $active = $this->runs->findActiveForUser($user);
        if (null !== $active) {
            return RecommendationRunReportModel::fromRun($active);
        }

        $run = new RecommendationRun($user, $this->clock->now());
        $this->stampProvider($run, $user);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->trimRunLog($user);

        return RecommendationRunReportModel::fromRun($run);
    }

    /**
     * Runs after the new run is flushed, so the new run counts inside the RunLogRetention window. resume() never trims:
     * a resumed run appends to its own log.
     */
    private function trimRunLog(User $user): void
    {
        $this->logs->deleteForUserOutsideRuns(
            $user,
            $this->runs->findNewestIdsForUser($user, RunLogRetention::RUNS),
        );
    }

    /**
     * Resumes the latest run only if it failed; any other latest run is a caller mistake, never a silent fresh start.
     *
     * @throws AiNotConfiguredException
     * @throws NoResumableRecommendationRunException
     */
    public function resume(User $user): RecommendationRunReportModel
    {
        if (!AiReadiness::of($this->configurator->settingsFor($user))) {
            throw new AiNotConfiguredException('This account has no AI model chosen yet.');
        }

        $latest = $this->runs->findLatestForUser($user);
        if (null === $latest || RunStatus::Failed !== $latest->getStatus()) {
            throw new NoResumableRecommendationRunException('There is no failed run to resume.');
        }

        $latest->resume();
        // Re-stamped: an account that switched model since the failure calls the new one, and its history must say so.
        $this->stampProvider($latest, $user);
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($latest);
    }

    /**
     * Copied onto the run, never read back from the editable configuration, so the history keeps each run's model.
     * Host only, tested with is_string(): a host of '0' survives, a malformed or hostless URL stamps null.
     */
    private function stampProvider(RecommendationRun $run, User $user): void
    {
        $settings = $this->configurator->settingsFor($user);
        $host = parse_url($settings?->getBaseUrl() ?? '', \PHP_URL_HOST);

        $run->stampProvider(\is_string($host) ? $host : null, $settings?->getModel());
    }
}

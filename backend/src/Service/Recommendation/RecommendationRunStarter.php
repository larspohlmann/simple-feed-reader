<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Creates or resumes the run a tick will advance. An account with no ready AI
 * configuration can never start one — there is nothing yet to call. An
 * already-active run is returned as-is, so a second click never opens a
 * duplicate.
 *
 * Whether to resume a failed run rather than begin again is the user's call,
 * made in the client (#329): a failed run keeps a frozen candidate snapshot
 * from when it first started, so silently resuming it can rank stale
 * candidates. start() therefore always begins fresh, and resume() is the
 * separate, explicit action — its frozen batch plan and recorded winners are
 * untouched by resume(), so the tick that follows continues exactly where the
 * failure happened.
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
    public function start(User $user): RecommendationRunReport
    {
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
            throw new AiNotConfiguredException('This account has no AI model chosen yet.');
        }

        $active = $this->runs->findActiveForUser($user);
        if (null !== $active) {
            return RecommendationRunReport::fromRun($active);
        }

        // The debug log lives only for the latest run (#309): a genuinely new
        // run starts its record clean. resume() keeps its own log instead.
        $this->logs->deleteForUser($user);

        $run = new RecommendationRun($user, $this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Resumes the latest run when it failed, continuing at the batch that
     * failed rather than redoing the ones that succeeded. The client offers
     * this only when it has seen a failed run, so a latest run that is missing
     * or in any other state is a caller mistake, not a silent fresh start.
     *
     * @throws AiNotConfiguredException
     * @throws NoResumableRecommendationRunException
     */
    public function resume(User $user): RecommendationRunReport
    {
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
            throw new AiNotConfiguredException('This account has no AI model chosen yet.');
        }

        $latest = $this->runs->findLatestForUser($user);
        if (null === $latest || RecommendationRun::STATUS_FAILED !== $latest->getStatus()) {
            throw new NoResumableRecommendationRunException('There is no failed run to resume.');
        }

        $latest->resume();
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($latest);
    }
}

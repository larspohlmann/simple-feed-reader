<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Creates or resumes the run a tick will advance. An account with no ready AI
 * configuration can never start one — there is nothing yet to call. An
 * already-active run is returned as-is, so a second click never opens a
 * duplicate; a failed run is resumed in place rather than restarted, per the
 * issue's "a re-run resumes at the failed batch" — its frozen candidate batch
 * plan and recorded winners are untouched by resume(), so the tick that
 * follows continues exactly where the failure happened.
 */
final readonly class RecommendationRunStarter
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private AiProviderConfigurator $configurator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
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

        $latest = $this->runs->findLatestForUser($user);
        if (null !== $latest && RecommendationRun::STATUS_FAILED === $latest->getStatus()) {
            $latest->resume();
            $this->entityManager->flush();

            return RecommendationRunReport::fromRun($latest);
        }

        $run = new RecommendationRun($user, $this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}

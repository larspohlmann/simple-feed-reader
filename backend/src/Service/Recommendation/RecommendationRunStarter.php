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
 * configuration cannot start one; an already-active run is returned as-is, so a
 * second click opens no duplicate.
 *
 * Resuming a failed run is the user's call in the client (#329): a failed run
 * holds a frozen candidate snapshot that may now be stale. So start() always
 * begins fresh, while resume() leaves the frozen batch plan and recorded
 * winners intact, letting the next tick continue where the failure happened.
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

        $run = new RecommendationRun($user, $this->clock->now());
        $this->stampProvider($run, $user);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->trimRunLog($user);

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Trims the account's run log to the retention window. It runs after the
     * new run is flushed, so the new run counts inside the window and the runs
     * holding a log stay exactly the constant. resume() never trims: a resumed
     * run appends to the log it already has (#309).
     */
    private function trimRunLog(User $user): void
    {
        $this->logs->deleteForUserOutsideRuns(
            $user,
            $this->runs->findNewestIdsForUser($user, RunLogRetention::RUNS),
        );
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
        // Re-stamped, not left as it was: an account that switched model
        // between the failure and the resume calls the new one, and a history
        // row naming the old one would be a lie about what was billed (#409).
        $this->stampProvider($latest, $user);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($latest);
    }

    /**
     * Copies the provider this run is about to call onto the run itself (#409).
     * Copied, not read back later, because the configuration is editable: a
     * history that renames last month's runs when the model changes is no
     * history.
     *
     * The host only. A saved base URL with no host stamps null, not a fragment:
     * `parse_url()` returns `false` on a malformed URL and `null` when there is
     * no host, both collapsing to null. A genuine host of `'0'` must survive, so
     * the check is `is_string()`, not truthiness, which `?:` would swallow.
     */
    private function stampProvider(RecommendationRun $run, User $user): void
    {
        $settings = $this->configurator->settingsFor($user);
        $host = parse_url($settings?->getBaseUrl() ?? '', \PHP_URL_HOST);

        $run->stampProvider(\is_string($host) ? $host : null, $settings?->getModel());
    }
}

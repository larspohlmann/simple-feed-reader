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

        $run = new RecommendationRun($user, $this->clock->now());
        $this->stampProvider($run, $user);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->trimRunLog($user);

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Trims the account's run log to the retention window. It runs after the
     * new run is flushed, so the new run is inside the window it is counted
     * against and the number of runs holding a log is exactly the constant --
     * trimming first would keep the window's worth of old runs plus this one.
     * resume() never comes here: a resumed run appends to the log it already
     * has (#309).
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
     * Copies the provider this run is about to call onto the run itself
     * (#409). Copied rather than read back through the account later: the
     * configuration is editable, and a history that renames last month's runs
     * when the model changes is not a history.
     *
     * The host only. A base URL the account saved but that has no host at all
     * stamps null rather than a fragment of one — a history row is worth less
     * with a wrong provider in it than with an empty one. `parse_url()`
     * returns `false` on a malformed URL and `null` when there is no host
     * component; both collapse to null. A genuine host of `'0'` must not:
     * it is a valid (if unusual) hostname, and PHP's `?:` treats the string
     * `'0'` as falsy, so a plain truthiness check would swallow it too.
     */
    private function stampProvider(RecommendationRun $run, User $user): void
    {
        $settings = $this->configurator->settingsFor($user);
        $host = parse_url($settings?->getBaseUrl() ?? '', \PHP_URL_HOST);

        $run->stampProvider(\is_string($host) ? $host : null, $settings?->getModel());
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\Profile\Exception\ProfileConnectionMissingException;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Opens a profile run; an active one is returned as it is, so a second start opens no duplicate. */
final readonly class ProfileRunStarter
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private ProfileConnections $profileConnections,
        private RecommendationRunLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProfileRunsLimiter,
    ) {
    }

    /**
     * Spends the ai_profile_runs budget only when a run is opened: returning the active run costs no provider call.
     *
     * @throws ProfileConnectionMissingException
     * @throws RateLimitedException
     */
    public function startManually(User $user): ProfileRun
    {
        $active = $this->profileRuns->findActiveForUser($user);
        if (null !== $active) {
            return $active;
        }
        if (null === $this->profileConnections->usableFor($user)) {
            throw new ProfileConnectionMissingException(ProfileConnections::MISSING);
        }
        $this->rateLimitGuard->enforceForUser($this->aiProfileRunsLimiter, $user);

        return $this->open($user, ProfileRunTrigger::Manual);
    }

    public function start(User $user, ProfileRunTrigger $trigger): ProfileRun
    {
        return $this->profileRuns->findActiveForUser($user) ?? $this->open($user, $trigger);
    }

    private function open(User $user, ProfileRunTrigger $trigger): ProfileRun
    {
        $profileRun = new ProfileRun($user, $trigger, $this->clock->now());
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->logs->deleteForUserOutsideProfileRuns(
            $user,
            $this->profileRuns->findNewestIdsForUser($user, RunLogRetention::RUNS),
        );

        return $profileRun;
    }
}

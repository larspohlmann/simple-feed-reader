<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Run\Support\IntervalElapsed;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep starts a profile run for: a schedule chosen, a connection that can build the
 * profile (none means skipped, not started and failed), no profile run in flight, and the newest one an interval old.
 */
final readonly class DueProfileRunFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private ProfileRunRepository $profileRuns,
        private ProfileConnections $profileConnections,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<User> */
    public function due(): array
    {
        $due = [];

        foreach ($this->settings->findWithProfileInterval() as $row) {
            if ($this->isDue($row)) {
                $due[] = $row->getUser();
            }
        }

        return $due;
    }

    private function isDue(RecommendationSettings $row): bool
    {
        $user = $row->getUser();
        $latest = $this->profileRuns->findLatestForUser($user);
        if (true === $latest?->getStatus()->isActive()) {
            return false;
        }

        $intervalHours = $row->profileSettings()->intervalHours
            ?? throw new \LogicException('A scheduled row has an interval.');
        if (!IntervalElapsed::since($latest?->getCreatedAt(), $intervalHours, $this->clock->now())) {
            return false;
        }

        return null !== $this->profileConnections->usableGiven($user, $row);
    }
}

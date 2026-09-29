<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Support\AiReadiness;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep should start a run for: a cadence chosen, AI ready, no run in flight, and the newest
 * run at least one interval old. Any run resets that clock, so a failed run waits a full interval before the next.
 */
final readonly class DueRecommendationRunFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private RecommendationRunRepository $runs,
        private AiProviderConfigurator $configurator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<User>
     */
    public function due(): array
    {
        $due = [];

        foreach ($this->settings->findWithAutoGenerateInterval() as $row) {
            $user = $row->getUser();

            if ($this->isDue($row, $user)) {
                $due[] = $user;
            }
        }

        return $due;
    }

    private function isDue(RecommendationSettings $row, User $user): bool
    {
        if (!AiReadiness::of($this->configurator->settingsFor($user))) {
            return false;
        }

        if (null !== $this->runs->findActiveForUser($user)) {
            return false;
        }

        $anchor = $this->runs->findLatestForUser($user)?->getCreatedAt();
        if (null === $anchor) {
            return true;
        }

        $intervalHours = $row->values()->autoGenerateIntervalHours;

        return $this->clock->now() >= $anchor->modify(\sprintf('+%d hours', $intervalHours));
    }
}

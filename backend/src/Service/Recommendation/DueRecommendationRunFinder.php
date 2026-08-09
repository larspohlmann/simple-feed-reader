<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep should start a run for right now (#333). A
 * user qualifies when they chose a cadence, their AI is ready, they have no
 * run in flight, and their newest run is at least one interval old. The
 * newest run's start time is the anchor, so any run — manual, worker, or cron
 * — resets the clock; a failed run therefore waits a full interval before the
 * next attempt rather than hammering a broken provider.
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
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
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

<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Ai\Support\AiReadiness;
use App\Service\Curation\Recommendation\Run\Support\IntervalElapsed;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep should start a run for: active, a cadence chosen, AI ready, no run in flight, and the
 * newest run an interval old. Any run resets that clock, so a failed run waits a full interval before the next.
 */
final readonly class DueRecommendationRunFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private RecommendationRunRepository $runs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<User>
     */
    public function due(): array
    {
        $due = [];
        $now = $this->clock->now();

        foreach ($this->settings->findWithAutoGenerateInterval($now) as $row) {
            if ($this->isDue($row, $now)) {
                $due[] = $row->getUser();
            }
        }

        return $due;
    }

    private function isDue(RecommendationSettings $row, \DateTimeImmutable $now): bool
    {
        $user = $row->getUser();
        if (!AiReadiness::of($user->getActiveAiProviderSettings())) {
            return false;
        }

        if (null !== $this->runs->findActiveForUser($user)) {
            return false;
        }

        return IntervalElapsed::since(
            $this->runs->findLatestForUser($user)?->getCreatedAt(),
            $row->values()->autoGenerateIntervalHours ?? throw new \LogicException('A scheduled row has an interval.'),
            $now,
        );
    }
}

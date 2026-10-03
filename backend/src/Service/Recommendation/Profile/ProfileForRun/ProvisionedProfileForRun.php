<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Profile\Model\RunProfileModel;
use App\Service\Recommendation\Profile\ProfileRunStarter;

final readonly class ProvisionedProfileForRun implements ProfileForRunInterface
{
    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private ProfileRunRepository $profileRuns,
        private ProfileRunStarter $starter,
    ) {
    }

    public function profileFor(User $user, \DateTimeImmutable $runCreatedAt): RunProfileModel
    {
        $stored = $this->recommendationSettings->findForUser($user)?->getStoredProfile()->getText();
        if (null !== $stored) {
            return RunProfileModel::ready($stored);
        }

        $latest = $this->profileRuns->findLatestForUser($user);
        if (null === $latest || ($latest->getStatus()->isTerminal() && $latest->getCreatedAt() < $runCreatedAt)) {
            $this->starter->start($user, ProfileRunTrigger::Recommendation);

            return RunProfileModel::building();
        }

        return match ($latest->getStatus()) {
            RunStatus::Failed => RunProfileModel::failed(
                $latest->getError() ?? throw new \LogicException('A failed profile run carries its error.'),
            ),
            RunStatus::Completed => RunProfileModel::ready(null),
            RunStatus::Pending, RunStatus::Running => RunProfileModel::building(),
            RunStatus::Cancelled => throw new \LogicException('A profile run is never cancelled.'),
        };
    }

    public function isBuildingFor(User $user): bool
    {
        return null !== $this->profileRuns->findActiveForUser($user);
    }
}

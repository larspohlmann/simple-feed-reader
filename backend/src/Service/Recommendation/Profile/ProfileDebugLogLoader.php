<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Profile\Model\ProfileDebugLogModel;

final readonly class ProfileDebugLogLoader
{
    public function __construct(
        private ProfileRunRepository $profileRuns,
        private RecommendationRunLogRepository $logs,
    ) {
    }

    public function forUser(User $user): ProfileDebugLogModel
    {
        $latest = $this->profileRuns->findLatestForUser($user);
        if (null === $latest) {
            return ProfileDebugLogModel::empty();
        }

        $profileRunId = $latest->requireId();

        return new ProfileDebugLogModel(
            $this->logs->listForProfileRun($user, $profileRunId),
            $this->logs->streamingTextForProfileRun($user, $profileRunId),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Profile;

use App\Entity\User;
use App\Enum\AiRunKind;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Curation\Recommendation\Profile\Model\ProfileDebugLogModel;

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
            $this->logs->listForRun($user, AiRunKind::Profile, $profileRunId),
            $this->logs->streamingTextForRun($user, AiRunKind::Profile, $profileRunId),
        );
    }
}

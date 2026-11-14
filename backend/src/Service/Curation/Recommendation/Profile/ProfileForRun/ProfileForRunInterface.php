<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileForRun;

use App\Entity\User;
use App\Service\Recommendation\Profile\Model\RunProfileModel;

/** The profile module's one face to recommendation runs: the current profile, or a profile run on its way. */
interface ProfileForRunInterface
{
    /** Starts a profile run when there is no profile and none has run since $runCreatedAt. */
    public function profileFor(User $user, \DateTimeImmutable $runCreatedAt): RunProfileModel;

    public function isBuildingFor(User $user): bool;
}

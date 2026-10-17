<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;

/** The two sweeps that spend AppView profile calls, for a caller that runs them back to back in one window. */
final readonly class ProfileSweeps
{
    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
    ) {
    }

    public function pictures(ProfileImageBudgetModel $budget): ProfileImageSweepReportModel
    {
        return $this->profileImageSweep->sweep($budget);
    }

    public function switches(ProfileImageBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        return $this->hiddenProfileSwitchSweep->sweep($budget);
    }
}

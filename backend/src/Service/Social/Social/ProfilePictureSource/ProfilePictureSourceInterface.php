<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ProfilePictureSource;

use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.profile_picture_source')]
interface ProfilePictureSourceInterface
{
    public const string REFRESH_AFTER = '-7 days';

    /** Records pictures on the due feeds of its network; never flushes. */
    public function refresh(ProfileCallBudgetModel $budget, \DateTimeImmutable $now): ProfileImageSweepReportModel;
}

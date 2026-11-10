<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\ProfileImageSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'profileImages', priority: 50)]
final readonly class ProfileImagesTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_CALLS = 1;

    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): ProfileImageSweepReportModel
    {
        return $this->profileImageSweep->sweep(
            ProfileCallBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_CALLS),
        );
    }

    public function idleReport(): ProfileImageSweepReportModel
    {
        return ProfileImageSweepReportModel::none();
    }
}

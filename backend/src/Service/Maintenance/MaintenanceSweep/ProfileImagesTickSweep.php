<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\ProfileImageSweep;
use Psr\Clock\ClockInterface;

final readonly class ProfileImagesTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_CALLS = 1;

    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'profileImages';
    }

    public static function priority(): int
    {
        return 50;
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

<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\Model\ImageVerificationBudgetModel;
use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\Maintenance\Model\SweepReportModel;
use Psr\Clock\ClockInterface;

final readonly class ImageVerificationTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_SECONDS = 45;

    public function __construct(
        private ImageVerificationSweep $imageVerificationSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'imageVerification';
    }

    public static function priority(): int
    {
        return 80;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->imageVerificationSweep->verifyDue(
            ImageVerificationBudgetModel::halfOfWhatIsLeft($deadline, $this->clock->now(), self::MAX_SECONDS),
        ));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(ImageVerificationReportModel::none());
    }

    private static function reportOf(ImageVerificationReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'measured' => $report->measured,
            'kept' => $report->kept,
            'dropped' => $report->dropped,
            'retried' => $report->retried,
        ]);
    }
}

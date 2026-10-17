<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\Model\PrivateFeedSyncReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use Psr\Clock\ClockInterface;

final readonly class SocialMaintenance
{
    private const int PROFILE_IMAGE_MAX_CALLS = 1;
    private const int PRIVATE_FEED_MAX_ACCOUNTS = 1;

    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private PrivateFeedSyncSweep $privateFeedSyncSweep,
        private ClockInterface $clock,
    ) {
    }

    public function refreshProfileImages(\DateTimeImmutable $deadline): ProfileImageSweepReportModel
    {
        return $this->profileImageSweep->sweep(
            ProfileImageBudgetModel::fittingBefore($deadline, $this->clock->now(), self::PROFILE_IMAGE_MAX_CALLS),
        );
    }

    public function syncPrivateFeeds(\DateTimeImmutable $deadline): PrivateFeedSyncReportModel
    {
        return $this->privateFeedSyncSweep->sweep(
            PrivateFeedSyncBudgetModel::fittingBefore($deadline, $this->clock->now(), self::PRIVATE_FEED_MAX_ACCOUNTS),
        );
    }
}

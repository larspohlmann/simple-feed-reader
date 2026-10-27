<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;
use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\PrivateFeedSyncReportModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;

/** The tick's sweeps that flush through the default EntityManager, which an aborted refresh leaves closed. */
final readonly class MaintenanceSweepsModel
{
    public function __construct(
        public ForYouSweepReportModel $recommendations,
        public DigestSweepReportModel $digests,
        public ImageVerificationReportModel $imageVerification,
        public FollowUpFetchesReportModel $followUpFetches,
        public ProfileImageSweepReportModel $profileImages,
        public HiddenProfileSwitchReportModel $hiddenProfiles,
        public PrivateFeedSyncReportModel $privateFeedSync,
        public SavedSearchMembershipSweepReportModel $savedSearchMemberships,
        public bool $skipped = false,
    ) {
    }

    public static function skippedAfterAbortedRefresh(): self
    {
        return new self(
            new ForYouSweepReportModel(0, 0, 0),
            new DigestSweepReportModel(0, 0, 0),
            new ImageVerificationReportModel(0, 0, 0, 0),
            FollowUpFetchesReportModel::none(),
            ProfileImageSweepReportModel::none(),
            HiddenProfileSwitchReportModel::none(),
            PrivateFeedSyncReportModel::none(),
            new SavedSearchMembershipSweepReportModel(0, 0, 0, false),
            skipped: true,
        );
    }
}

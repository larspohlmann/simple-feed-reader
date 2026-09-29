<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;
use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;

/** The tick's sweeps that flush through the default EntityManager, which an aborted refresh leaves closed. */
final readonly class MaintenanceSweepsModel
{
    public function __construct(
        public ForYouSweepReportModel $recommendations,
        public DigestSweepReportModel $digests,
        public ImageVerificationReportModel $imageVerification,
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
            new SavedSearchMembershipSweepReportModel(0, 0, 0, false),
            skipped: true,
        );
    }
}

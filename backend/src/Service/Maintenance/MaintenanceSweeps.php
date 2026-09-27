<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Image\ImageVerificationReport;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Recommendation\ForYouSweepReport;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;

/** The tick's sweeps that flush through the default EntityManager, which an aborted refresh leaves closed. */
final readonly class MaintenanceSweeps
{
    public function __construct(
        public ForYouSweepReport $recommendations,
        public DigestSweepReport $digests,
        public ImageVerificationReport $imageVerification,
        public SavedSearchMembershipSweepReport $savedSearchMemberships,
        public bool $skipped = false,
    ) {
    }

    public static function skippedAfterAbortedRefresh(): self
    {
        return new self(
            new ForYouSweepReport(0, 0, 0),
            new DigestSweepReport(0, 0, 0),
            new ImageVerificationReport(0, 0, 0, 0),
            new SavedSearchMembershipSweepReport(0, 0, 0, false),
            skipped: true,
        );
    }
}

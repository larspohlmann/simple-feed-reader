<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Trending\Model\TrendingRebuildReportModel;

/** The tick's sweeps that recompute views of the entries: saved-search memberships, then Trending. */
final readonly class DerivedViewsReportModel
{
    public function __construct(
        public SavedSearchMembershipSweepReportModel $savedSearchMemberships,
        public TrendingRebuildReportModel $trending,
    ) {
    }

    public static function none(): self
    {
        return new self(new SavedSearchMembershipSweepReportModel(0, 0, 0, false), TrendingRebuildReportModel::none());
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Search\Membership\Pass;

use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;

/** The counters one sweep run accumulates; the report is their read-only end state. */
final class SweepTally
{
    public int $searchesSwept = 0;
    public int $entriesScanned = 0;
    public int $matchesInserted = 0;

    public function caughtUp(): SavedSearchMembershipSweepReportModel
    {
        return $this->report(true);
    }

    public function stoppedShort(): SavedSearchMembershipSweepReportModel
    {
        return $this->report(false);
    }

    private function report(bool $caughtUp): SavedSearchMembershipSweepReportModel
    {
        return new SavedSearchMembershipSweepReportModel(
            $this->searchesSwept,
            $this->entriesScanned,
            $this->matchesInserted,
            $caughtUp,
        );
    }
}

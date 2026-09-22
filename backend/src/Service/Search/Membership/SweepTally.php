<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

/** The counters one sweep run accumulates; the report is their read-only end state. */
final class SweepTally
{
    public int $searchesSwept = 0;
    public int $entriesScanned = 0;
    public int $matchesInserted = 0;

    public function toReport(bool $caughtUp): SavedSearchMembershipSweepReport
    {
        return new SavedSearchMembershipSweepReport(
            $this->searchesSwept,
            $this->entriesScanned,
            $this->matchesInserted,
            $caughtUp,
        );
    }
}

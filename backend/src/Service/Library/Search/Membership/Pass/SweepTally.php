<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\Pass;

use App\Service\Library\Search\Membership\Model\MarkGroupWalkReportModel;

/** The deadline of one walk and the counters it accumulates; the report is their read-only end state. */
final class SweepTally
{
    private int $membersSwept = 0;
    private int $entriesScanned = 0;
    private int $matchesInserted = 0;

    public function __construct(public readonly \DateTimeImmutable $deadline)
    {
    }

    public function recordGroup(int $members): void
    {
        $this->membersSwept += $members;
    }

    public function recordChunk(int $scanned, int $inserted): void
    {
        $this->entriesScanned += $scanned;
        $this->matchesInserted += $inserted;
    }

    public function caughtUp(): MarkGroupWalkReportModel
    {
        return new MarkGroupWalkReportModel($this->membersSwept, $this->entriesScanned, $this->matchesInserted, true);
    }

    public function stoppedShort(): MarkGroupWalkReportModel
    {
        return new MarkGroupWalkReportModel($this->membersSwept, $this->entriesScanned, $this->matchesInserted, false);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Service\Library\Search\Membership\Model\MarkGroupWalkReportModel;

final readonly class YourStreamSweepReportModel
{
    public function __construct(
        public int $usersSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
    }

    public static function fromWalk(MarkGroupWalkReportModel $report): self
    {
        return new self($report->membersSwept, $report->entriesScanned, $report->matchesInserted, $report->caughtUp);
    }

    public static function none(): self
    {
        return new self(0, 0, 0, false);
    }

    /** @return array{usersSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool} */
    public function toLogContext(): array
    {
        return [
            'usersSwept' => $this->usersSwept,
            'entriesScanned' => $this->entriesScanned,
            'matchesInserted' => $this->matchesInserted,
            'caughtUp' => $this->caughtUp,
        ];
    }
}

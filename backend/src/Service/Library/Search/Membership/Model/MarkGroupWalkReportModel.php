<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\Model;

final readonly class MarkGroupWalkReportModel
{
    public function __construct(
        public int $membersSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
    }
}

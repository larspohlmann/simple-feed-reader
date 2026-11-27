<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

final readonly class ForYouStreamSweepReportModel
{
    public function __construct(
        public int $usersSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
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

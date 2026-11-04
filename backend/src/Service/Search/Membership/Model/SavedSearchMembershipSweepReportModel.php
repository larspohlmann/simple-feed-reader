<?php

declare(strict_types=1);

namespace App\Service\Search\Membership\Model;

final readonly class SavedSearchMembershipSweepReportModel
{
    public function __construct(
        public int $searchesSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, false);
    }

    /**
     * @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}
     */
    public function toLogContext(): array
    {
        return [
            'searchesSwept' => $this->searchesSwept,
            'entriesScanned' => $this->entriesScanned,
            'matchesInserted' => $this->matchesInserted,
            'caughtUp' => $this->caughtUp,
        ];
    }
}

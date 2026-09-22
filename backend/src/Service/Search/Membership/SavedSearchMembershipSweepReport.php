<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

final readonly class SavedSearchMembershipSweepReport
{
    public function __construct(
        public int $searchesSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
    }

    /**
     * @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}
     */
    public function toArray(): array
    {
        return [
            'searchesSwept' => $this->searchesSwept,
            'entriesScanned' => $this->entriesScanned,
            'matchesInserted' => $this->matchesInserted,
            'caughtUp' => $this->caughtUp,
        ];
    }
}

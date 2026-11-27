<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

final readonly class InterestTermSweepReportModel
{
    public function __construct(
        public int $due,
        public int $stored,
        public int $failed,
        public int $raritiesRefreshed,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    /** @return array{due: int, stored: int, failed: int, raritiesRefreshed: int} */
    public function toLogContext(): array
    {
        return [
            'due' => $this->due,
            'stored' => $this->stored,
            'failed' => $this->failed,
            'raritiesRefreshed' => $this->raritiesRefreshed,
        ];
    }
}

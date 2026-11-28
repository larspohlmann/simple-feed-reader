<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

final readonly class InterestTermSweepReportModel
{
    public function __construct(public int $raritiesRefreshed)
    {
    }

    public static function none(): self
    {
        return new self(0);
    }

    /** @return array{raritiesRefreshed: int} */
    public function toLogContext(): array
    {
        return ['raritiesRefreshed' => $this->raritiesRefreshed];
    }
}

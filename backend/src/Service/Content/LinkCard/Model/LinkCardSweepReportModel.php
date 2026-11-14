<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

final readonly class LinkCardSweepReportModel
{
    public function __construct(
        public int $considered,
        public int $completed,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    /** @return array{considered: int, completed: int} */
    public function toLogContext(): array
    {
        return ['considered' => $this->considered, 'completed' => $this->completed];
    }
}

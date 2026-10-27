<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

final readonly class TranscriptSweepReportModel
{
    public function __construct(public int $claimed, public int $filled)
    {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    /** @return array{claimed: int, filled: int} */
    public function toLogContext(): array
    {
        return ['claimed' => $this->claimed, 'filled' => $this->filled];
    }
}

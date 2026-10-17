<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class HiddenProfileSwitchReportModel
{
    public function __construct(
        public int $started,
        public int $finished,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function counting(HiddenProfileMoveOutcome $outcome): self
    {
        return match ($outcome) {
            HiddenProfileMoveOutcome::Started => new self($this->started + 1, $this->finished),
            HiddenProfileMoveOutcome::Finished => new self($this->started, $this->finished + 1),
            HiddenProfileMoveOutcome::Skipped, HiddenProfileMoveOutcome::Waiting => $this,
        };
    }

    /** @return array{started: int, finished: int} */
    public function toLogContext(): array
    {
        return ['started' => $this->started, 'finished' => $this->finished];
    }
}

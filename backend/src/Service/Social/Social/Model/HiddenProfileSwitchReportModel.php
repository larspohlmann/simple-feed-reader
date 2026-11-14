<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class HiddenProfileSwitchReportModel
{
    public function __construct(
        public int $started,
        public int $finished,
        public int $waiting = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    #[\NoDiscard]
    public function counting(HiddenProfileMoveOutcome $outcome): self
    {
        return match ($outcome) {
            HiddenProfileMoveOutcome::Started => clone($this, ['started' => $this->started + 1]),
            HiddenProfileMoveOutcome::Finished => clone($this, ['finished' => $this->finished + 1]),
            HiddenProfileMoveOutcome::Waiting => clone($this, ['waiting' => $this->waiting + 1]),
            HiddenProfileMoveOutcome::Skipped => $this,
        };
    }

    public function isIdle(): bool
    {
        return 0 === $this->started + $this->finished + $this->waiting;
    }

    /** @return array{started: int, finished: int, waiting: int} */
    public function toLogContext(): array
    {
        return ['started' => $this->started, 'finished' => $this->finished, 'waiting' => $this->waiting];
    }
}

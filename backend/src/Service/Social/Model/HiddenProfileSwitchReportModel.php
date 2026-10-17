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

    public function counting(HiddenProfileMoveOutcome $outcome): self
    {
        return match ($outcome) {
            HiddenProfileMoveOutcome::Started => new self($this->started + 1, $this->finished, $this->waiting),
            HiddenProfileMoveOutcome::Finished => new self($this->started, $this->finished + 1, $this->waiting),
            HiddenProfileMoveOutcome::Waiting => new self($this->started, $this->finished, $this->waiting + 1),
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

<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\Model;

final readonly class StoryAiReportModel
{
    public function __construct(public int $usable, public int $unusable, public int $failed)
    {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    /** @param StoryAiOutcomeModel<mixed> $outcome */
    #[\NoDiscard]
    public function counting(StoryAiOutcomeModel $outcome): self
    {
        return match (true) {
            $outcome->isUsable() => clone($this, ['usable' => $this->usable + 1]),
            $outcome->isFailed() => clone($this, ['failed' => $this->failed + 1]),
            default => clone($this, ['unusable' => $this->unusable + 1]),
        };
    }

    #[\NoDiscard]
    public function plus(self $other): self
    {
        return new self(
            $this->usable + $other->usable,
            $this->unusable + $other->unusable,
            $this->failed + $other->failed,
        );
    }

    /** @return array{usable: int, unusable: int, failed: int} */
    public function toLogContext(): array
    {
        return ['usable' => $this->usable, 'unusable' => $this->unusable, 'failed' => $this->failed];
    }
}

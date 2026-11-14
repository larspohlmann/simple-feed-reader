<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

final readonly class PrivateFeedImportModel
{
    public function __construct(
        public int $added = 0,
        public int $alreadySubscribed = 0,
        public int $overLimit = 0,
    ) {
    }

    #[\NoDiscard]
    public function counting(PrivateFeedOutcome $outcome): self
    {
        return match ($outcome) {
            PrivateFeedOutcome::Created => clone($this, ['added' => $this->added + 1]),
            PrivateFeedOutcome::AlreadySubscribed => clone($this, [
                'alreadySubscribed' => $this->alreadySubscribed + 1,
            ]),
        };
    }

    #[\NoDiscard]
    public function countingOverLimit(): self
    {
        return clone($this, ['overLimit' => $this->overLimit + 1]);
    }
}

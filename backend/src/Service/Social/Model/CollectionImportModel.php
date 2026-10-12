<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class CollectionImportModel
{
    public function __construct(
        public int $added = 0,
        public int $alreadySubscribed = 0,
        public int $overLimit = 0,
    ) {
    }

    public function counting(PrivateFeedOutcome $outcome): self
    {
        return match ($outcome) {
            PrivateFeedOutcome::Created => new self($this->added + 1, $this->alreadySubscribed, $this->overLimit),
            PrivateFeedOutcome::AlreadySubscribed => new self(
                $this->added,
                $this->alreadySubscribed + 1,
                $this->overLimit,
            ),
        };
    }

    public function countingOverLimit(): self
    {
        return new self($this->added, $this->alreadySubscribed, $this->overLimit + 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class DiscussionCounts
{
    private function __construct(
        public ?int $score,
        public ?int $commentCount,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null);
    }

    public static function of(?int $score, ?int $commentCount): self
    {
        return new self($score, $commentCount);
    }

    public function isUnknown(): bool
    {
        return $this->score === null && $this->commentCount === null;
    }
}

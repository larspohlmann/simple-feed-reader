<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class DiscussionCounts
{
    private function __construct(
        public ?int $score,
        public ?int $commentCount,
        public ?int $likeCount,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public static function fromScoreAndComments(?int $score, ?int $commentCount): self
    {
        return new self($score, $commentCount, null);
    }

    public static function fromLikes(int $likeCount): self
    {
        return new self(null, null, $likeCount);
    }

    public static function fromCommentsAndLikes(?int $commentCount, ?int $likeCount): self
    {
        return new self(null, $commentCount, $likeCount);
    }
}

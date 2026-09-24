<?php

declare(strict_types=1);

namespace App\Service\Comments;

final readonly class CommentsResult
{
    /** @param list<EntryComment> $comments */
    private function __construct(
        public CommentsStatus $status,
        public array $comments = [],
        public ?int $retryAfter = null,
    ) {
    }

    /** @param list<EntryComment> $comments */
    public static function ok(array $comments): self
    {
        return new self(CommentsStatus::Ok, $comments);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(CommentsStatus::Throttled, retryAfter: $retryAfter);
    }

    public static function failed(): self
    {
        return new self(CommentsStatus::Failed);
    }
}

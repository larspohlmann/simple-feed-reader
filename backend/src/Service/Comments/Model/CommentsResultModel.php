<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CommentsResultModel
{
    /** @param list<EntryCommentModel> $comments */
    private function __construct(
        public CommentsStatus $status,
        public array $comments = [],
        public ?int $retryAfter = null,
        public int $omitted = 0,
        public ?\DateTimeImmutable $fetchedAt = null,
    ) {
    }

    public static function ok(CommentsPageModel $page, \DateTimeImmutable $fetchedAt): self
    {
        return new self(CommentsStatus::Ok, $page->comments, null, $page->omitted, $fetchedAt);
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

<?php

declare(strict_types=1);

namespace App\Entity;

/** Where a discussion's counts live; a null count keeps the stored one. */
interface DiscussionCountStoreInterface
{
    public function recordCounts(DiscussionCounts $counts): void;

    public function getCommentCount(): ?int;

    /** A read sees only part of a thread, a timeline page most of all, so the count only ever grows. */
    public function recordSelfReplyCount(int $selfReplyCount): void;

    public function getSelfReplyCount(): int;
}

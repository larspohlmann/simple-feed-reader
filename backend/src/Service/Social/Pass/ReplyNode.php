<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Model\SocialCountsModel;

final readonly class ReplyNode
{
    /**
     * @param \Closure(?\DateTimeImmutable): EntryCommentModel $commentOf builds the comment only when the page keeps it
     * @param list<ReplyNode>          $replies
     */
    public function __construct(
        public ?\DateTimeImmutable $publishedAt,
        private \Closure $commentOf,
        public array $replies,
        public ?string $url,
        public bool $byEntryAuthor,
        public ?SocialCountsModel $counts,
    ) {
    }

    public function comment(): EntryCommentModel
    {
        return ($this->commentOf)($this->publishedAt);
    }

    public function sortableTime(): int
    {
        return $this->publishedAt?->getTimestamp() ?? PHP_INT_MIN;
    }
}

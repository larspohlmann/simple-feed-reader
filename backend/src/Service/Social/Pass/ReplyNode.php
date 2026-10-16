<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Comments\Model\EntryCommentModel;

final readonly class ReplyNode
{
    /**
     * @param \Closure(?\DateTimeImmutable): EntryCommentModel $commentOf builds the comment only when the page keeps it
     * @param list<ReplyNode>          $replies
     */
    public function __construct(
        public ?\DateTimeImmutable $publishedAt,
        private \Closure $commentOf,
        public array $replies = [],
    ) {
    }

    public function comment(): EntryCommentModel
    {
        return ($this->commentOf)($this->publishedAt);
    }
}

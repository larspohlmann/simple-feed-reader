<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CommentsPageModel
{
    /** @param list<EntryCommentModel> $comments */
    public function __construct(
        public array $comments,
        public int $omitted = 0,
    ) {
    }
}

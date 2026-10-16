<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Comments\Model\EntryCommentModel;

final readonly class ReplyNodeModel
{
    /** @param list<ReplyNodeModel> $replies */
    public function __construct(public EntryCommentModel $comment, public array $replies = [])
    {
    }
}

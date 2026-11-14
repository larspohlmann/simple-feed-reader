<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

use App\Enum\VoteScore;

final readonly class CommentVoteModel
{
    public function __construct(public string $address, public VoteScore $myVote, public bool $canReply)
    {
    }
}

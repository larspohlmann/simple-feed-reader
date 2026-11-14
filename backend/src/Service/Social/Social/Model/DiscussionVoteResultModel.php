<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\VoteScore;

final readonly class DiscussionVoteResultModel
{
    public function __construct(public VoteScore $myVote, public int $score)
    {
    }
}

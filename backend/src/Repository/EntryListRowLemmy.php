<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostCommunity;
use App\Enum\VoteScore;

final readonly class EntryListRowLemmy
{
    public function __construct(
        public ?int $score,
        public ?PostCommunity $community,
        /** null unless the reader has a Lemmy account; None for every post they can vote on */
        public ?VoteScore $myVote,
    ) {
    }
}

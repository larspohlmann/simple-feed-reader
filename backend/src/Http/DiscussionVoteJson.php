<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Social\Model\DiscussionVoteResultModel;

final class DiscussionVoteJson
{
    /** @return array{myVote: int, score: int} */
    public static function result(DiscussionVoteResultModel $result): array
    {
        return ['myVote' => $result->myVote->value, 'score' => $result->score];
    }

    private function __construct()
    {
    }
}

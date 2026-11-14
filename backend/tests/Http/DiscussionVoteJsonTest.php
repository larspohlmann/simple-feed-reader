<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\VoteScore;
use App\Http\DiscussionVoteJson;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use PHPUnit\Framework\TestCase;

final class DiscussionVoteJsonTest extends TestCase
{
    public function testAResultCarriesTheVoteAndTheScore(): void
    {
        self::assertSame(
            ['myVote' => -1, 'score' => -3],
            DiscussionVoteJson::result(new DiscussionVoteResultModel(VoteScore::Down, -3)),
        );
    }

    public function testAReadCarriesTheVoteOnly(): void
    {
        self::assertSame(['myVote' => 1], DiscussionVoteJson::myVote(VoteScore::Up));
        self::assertSame(['myVote' => 0], DiscussionVoteJson::myVote(VoteScore::None));
    }
}

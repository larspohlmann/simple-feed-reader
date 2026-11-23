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
            ['myVote' => -1, 'score' => -3, 'discussionHomeUrl' => null],
            DiscussionVoteJson::result(new DiscussionVoteResultModel(VoteScore::Down, -3)),
        );
    }

    public function testAResultCarriesThePostsHomeAddress(): void
    {
        self::assertSame(
            ['myVote' => 1, 'score' => 7, 'discussionHomeUrl' => 'https://lemmy.example/post/9'],
            DiscussionVoteJson::result(
                new DiscussionVoteResultModel(VoteScore::Up, 7, 'https://lemmy.example/post/9'),
            ),
        );
    }
}

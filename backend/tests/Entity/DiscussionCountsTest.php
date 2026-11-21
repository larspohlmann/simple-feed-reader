<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DiscussionCounts;
use PHPUnit\Framework\TestCase;

final class DiscussionCountsTest extends TestCase
{
    public function testUnknownCarriesNothing(): void
    {
        $counts = DiscussionCounts::unknown();

        self::assertNull($counts->score);
        self::assertNull($counts->commentCount);
        self::assertNull($counts->likeCount);
    }

    public function testCommentsAndLikesCarryNoScore(): void
    {
        $counts = DiscussionCounts::fromCommentsAndLikes(3, 9);

        self::assertNull($counts->score);
        self::assertSame(3, $counts->commentCount);
        self::assertSame(9, $counts->likeCount);
    }

    public function testScoreAndCommentsCarryNoLikeCount(): void
    {
        self::assertNull(DiscussionCounts::fromScoreAndComments(1, 2)->likeCount);
    }
}

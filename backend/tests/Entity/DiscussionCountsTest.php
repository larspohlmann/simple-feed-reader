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
    }
}

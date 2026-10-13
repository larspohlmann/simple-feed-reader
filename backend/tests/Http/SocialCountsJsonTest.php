<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SocialCountsJson;
use App\Service\Social\Model\SocialCountsModel;
use PHPUnit\Framework\TestCase;

final class SocialCountsJsonTest extends TestCase
{
    public function testBlueskyCountsCarryTheirQuotes(): void
    {
        self::assertSame(
            ['likes' => 12, 'reposts' => 3, 'replies' => 4, 'quotes' => 1],
            SocialCountsJson::of(new SocialCountsModel(12, 3, 4, 1)),
        );
    }

    public function testMastodonCountsSayTheyHaveNoQuotes(): void
    {
        self::assertSame(
            ['likes' => 5, 'reposts' => 2, 'replies' => 1, 'quotes' => null],
            SocialCountsJson::of(new SocialCountsModel(5, 2, 1, null)),
        );
    }
}

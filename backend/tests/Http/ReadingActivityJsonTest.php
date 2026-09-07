<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ReadingActivityJson;
use PHPUnit\Framework\TestCase;

/**
 * The reading-activity wire shape (#896): one entry per window day in order,
 * quiet days zero-filled, plus the window total.
 */
final class ReadingActivityJsonTest extends TestCase
{
    public function testZeroFillsQuietDaysKeepsTheDayOrderAndCarriesTheFeedRanking(): void
    {
        $payload = ReadingActivityJson::of(
            ['2026-09-05', '2026-09-06', '2026-09-07'],
            ['2026-09-06' => 3, '2026-09-07' => 1],
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
        );

        self::assertSame(
            [
                ['date' => '2026-09-05', 'count' => 0],
                ['date' => '2026-09-06', 'count' => 3],
                ['date' => '2026-09-07', 'count' => 1],
            ],
            $payload['days'],
        );
        self::assertSame(4, $payload['total']);
        self::assertSame(
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
            $payload['topFeedsByRead'],
        );
    }

    public function testAnAccountWithNoReadsGetsAllZerosAZeroTotalAndAnEmptyRanking(): void
    {
        $payload = ReadingActivityJson::of(['2026-09-06', '2026-09-07'], [], []);

        self::assertSame(
            [
                ['date' => '2026-09-06', 'count' => 0],
                ['date' => '2026-09-07', 'count' => 0],
            ],
            $payload['days'],
        );
        self::assertSame(0, $payload['total']);
        self::assertSame([], $payload['topFeedsByRead']);
    }
}

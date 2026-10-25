<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ResponseSizeLimit;
use PHPUnit\Framework\TestCase;

final class ResponseSizeLimitTest extends TestCase
{
    /** #1452: a 20 MB feed admits 99 % of chart podcasts; images keep the 5 MB they always had. */
    public function testTheLimitsAreTheMeasuredOnes(): void
    {
        self::assertSame(20_000_000, ResponseSizeLimit::Feed->bytes());
        self::assertSame(5_000_000, ResponseSizeLimit::Download->bytes());
        self::assertSame(2_000_000, ResponseSizeLimit::Api->bytes());
        self::assertSame(64_000, ResponseSizeLimit::ChapterFile->bytes());
    }

    public function testALimitReadsInMegabytesWhenItIsWholeAndInKilobytesOtherwise(): void
    {
        self::assertSame('20 MB', ResponseSizeLimit::Feed->label());
        self::assertSame('64 kB', ResponseSizeLimit::ChapterFile->label());
    }
}

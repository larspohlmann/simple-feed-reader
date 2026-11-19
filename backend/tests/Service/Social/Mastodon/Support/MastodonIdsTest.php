<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Social\Mastodon\Support\MastodonIds;
use PHPUnit\Framework\TestCase;

final class MastodonIdsTest extends TestCase
{
    public function testComparesByLengthThenDigits(): void
    {
        self::assertTrue(MastodonIds::atOrBefore('99', '100'));
        self::assertFalse(MastodonIds::atOrBefore('101', '100'));
        self::assertTrue(MastodonIds::atOrBefore('100', '100'));
        self::assertSame('110', MastodonIds::newest(['9', '110', '101']));
    }

    public function testComparesLongIdsDigitByDigit(): void
    {
        self::assertTrue(MastodonIds::atOrBefore('123456789012345678901', '123456789012345678902'));
        self::assertFalse(MastodonIds::atOrBefore('123456789012345678902', '123456789012345678901'));
        self::assertSame(
            '123456789012345678902',
            MastodonIds::newest(['123456789012345678901', '123456789012345678902']),
        );
    }
}

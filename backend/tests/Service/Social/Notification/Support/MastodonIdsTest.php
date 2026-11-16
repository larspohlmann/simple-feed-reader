<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Support;

use App\Service\Social\Notification\Support\MastodonIds;
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
}

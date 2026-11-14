<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Support;

use App\Service\Ai\Support\ReplyField;
use PHPUnit\Framework\TestCase;

final class ReplyFieldTest extends TestCase
{
    public function testTextIsANonEmptyStringOrNothing(): void
    {
        self::assertSame('gen-1', ReplyField::text('gen-1'));
        self::assertNull(ReplyField::text(''));
        self::assertNull(ReplyField::text(7));
        self::assertNull(ReplyField::text(null));
    }

    public function testACountIsANonNegativeIntegerAndAnythingElseReadsZero(): void
    {
        self::assertSame(12, ReplyField::count(12));
        self::assertSame(0, ReplyField::count(0));
        self::assertSame(0, ReplyField::count(-5));
        self::assertSame(0, ReplyField::count('12'));
        self::assertSame(0, ReplyField::count(null));
    }
}

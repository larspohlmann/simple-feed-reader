<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\WidthQuery;
use PHPUnit\Framework\TestCase;

final class WidthQueryTest extends TestCase
{
    public function testAppendsAWidth(): void
    {
        self::assertSame('https://h/a.jpg?w=264', WidthQuery::withWidth('https://h/a.jpg', 264));
        self::assertSame('https://h/a.jpg?quality=90&w=264', WidthQuery::withWidth('https://h/a.jpg?quality=90', 264));
    }

    public function testReplacesAnExistingWidthKeepingTheRestVerbatim(): void
    {
        self::assertSame(
            'https://h/a.jpg?quality=82&strip=all&w=520',
            WidthQuery::withWidth('https://h/a.jpg?quality=82&strip=all&w=1600', 520),
        );
        self::assertSame(
            'https://h/a.jpg?w=264&crop=0,0,100,100',
            WidthQuery::withWidth('https://h/a.jpg?w=1600&crop=0,0,100,100', 264),
        );
    }

    public function testLeavesAWidthLookalikeAlone(): void
    {
        self::assertSame('https://h/a.jpg?vw=3&w=264', WidthQuery::withWidth('https://h/a.jpg?vw=3', 264));
    }

    public function testOnlyTheFirstWidthIsReplaced(): void
    {
        self::assertSame('https://h/a.jpg?w=9&u=x&w=2', WidthQuery::withWidth('https://h/a.jpg?w=1&u=x&w=2', 9));
    }
}

<?php

declare(strict_types=1);

// Fixtures for InvocationMatchersOnThisRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\Fixtures\Matchers;

use PHPUnit\Framework\TestCase;

final class MatcherSpellings extends TestCase
{
    public function testSpellings(): void
    {
        $this->once();
        self::once();
        static::never();
        self::exactly(2);
        self::assertTrue(true);
    }
}

final class NotATest
{
    public static function once(): void
    {
    }

    public function call(): void
    {
        self::once();
    }
}

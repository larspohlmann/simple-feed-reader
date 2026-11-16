<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\NaiveUtc;
use PHPUnit\Framework\TestCase;

final class NaiveUtcTest extends TestCase
{
    public function testAStoredTimeIsReadAsUtcWhateverTheDefaultZone(): void
    {
        $defaultZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $parsed = NaiveUtc::parse('2031-05-14 10:00:00');
        } finally {
            date_default_timezone_set($defaultZone);
        }

        self::assertSame('UTC', $parsed->getTimezone()->getName());
        self::assertSame('2031-05-14T10:00:00+00:00', $parsed->format(\DATE_ATOM));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Support;

use App\Service\Social\Notification\Support\Excerpt;
use PHPUnit\Framework\TestCase;

final class ExcerptTest extends TestCase
{
    public function testNullAndBlankAreNull(): void
    {
        self::assertNull(Excerpt::of(null));
        self::assertNull(Excerpt::of("  \n "));
    }

    public function testTrimsAndCutsToTwoHundredEightyCharacters(): void
    {
        self::assertSame('hi', Excerpt::of('  hi '));
        self::assertSame(str_repeat('ä', 280) . '…', Excerpt::of(str_repeat('ä', 300)));
        self::assertSame(str_repeat('a', 280), Excerpt::of(str_repeat('a', 280)));
    }

    public function testInvalidUtf8IsScrubbedSoTheExcerptStaysEncodable(): void
    {
        $excerpt = Excerpt::of("caf\xE9 ok");

        self::assertIsString($excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }
}

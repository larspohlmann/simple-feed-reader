<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Support;

use App\Service\Social\Notification\Support\Excerpt;
use PHPUnit\Framework\TestCase;

final class ExcerptTest extends TestCase
{
    public function testNullAndBlankAreNull(): void
    {
        self::assertNull(Excerpt::cutText(null));
        self::assertNull(Excerpt::cutText("  \n "));
    }

    public function testTrimsAndCutsToTwoHundredEightyCharacters(): void
    {
        self::assertSame('hi', Excerpt::cutText('  hi '));
        self::assertSame(str_repeat('ä', 280) . '…', Excerpt::cutText(str_repeat('ä', 300)));
        self::assertSame(str_repeat('a', 280), Excerpt::cutText(str_repeat('a', 280)));
    }

    public function testInvalidUtf8IsScrubbedSoTheExcerptStaysEncodable(): void
    {
        $excerpt = Excerpt::cutText("caf\xE9 ok");

        self::assertIsString($excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    public function testOfHtmlReadsBlocksAsPlainTextAndNullsBlank(): void
    {
        self::assertSame('one two', Excerpt::cutHtml('<p>one</p><p>two</p>'));
        self::assertNull(Excerpt::cutHtml(null));
        self::assertNull(Excerpt::cutHtml('<p> </p>'));
    }
}

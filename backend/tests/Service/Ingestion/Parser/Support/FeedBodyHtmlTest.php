<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\FeedBodyHtml;
use PHPUnit\Framework\TestCase;

final class FeedBodyHtmlTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        self::assertNull(FeedBodyHtml::of(null));
    }

    public function testATagFreeBodyStillBecomesParagraphsAndBreaks(): void
    {
        self::assertSame('<p>one<br>two</p>', FeedBodyHtml::of("one\ntwo"));
    }

    public function testPastedTextInAParagraphGetsItsBreaks(): void
    {
        self::assertSame(
            '<p>Caption<br><br>(Photo: Jane)</p>',
            FeedBodyHtml::of("<p>Caption\n\n(Photo: Jane)</p>"),
        );
    }
}

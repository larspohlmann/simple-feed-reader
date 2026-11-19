<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Parser\Support;

use App\Service\Ingestion\Parser\Support\FeedBodyHtml;
use PHPUnit\Framework\TestCase;

final class FeedBodyHtmlTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        self::assertNull(FeedBodyHtml::fromBody(null));
    }

    public function testATagFreeBodyStillBecomesParagraphsAndBreaks(): void
    {
        self::assertSame('<p>one<br>two</p>', FeedBodyHtml::fromBody("one\ntwo"));
    }

    public function testPastedTextInAParagraphGetsItsBreaks(): void
    {
        self::assertSame(
            '<p>Caption<br><br>(Photo: Jane)</p>',
            FeedBodyHtml::fromBody("<p>Caption\n\n(Photo: Jane)</p>"),
        );
    }
}

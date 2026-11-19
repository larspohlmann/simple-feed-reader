<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\Support;

use App\Service\Foundation\Text\Support\LinkFreeText;
use PHPUnit\Framework\TestCase;

final class LinkFreeTextTest extends TestCase
{
    public function testAWebLinkIsRemoved(): void
    {
        self::assertSame('Read  now', LinkFreeText::stripLinks('Read https://example.com/the-day?a=1 now'));
        self::assertSame('Read ', LinkFreeText::stripLinks('Read http://example.com'));
    }

    public function testTextWithoutALinkStaysAsItIs(): void
    {
        self::assertSame(
            'example.com/news and ftp://host',
            LinkFreeText::stripLinks('example.com/news and ftp://host'),
        );
    }

    public function testASchemeInsideAWordIsNoLink(): void
    {
        self::assertSame('xhttps://example.com', LinkFreeText::stripLinks('xhttps://example.com'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\UndeclaredPrefixes;
use PHPUnit\Framework\TestCase;

final class UndeclaredPrefixesTest extends TestCase
{
    public function testDeclaresAnUndeclaredPrefixOnTheRootElement(): void
    {
        $xml = '<?xml version="1.0"?><!-- c --><rss version="2.0"><item><media:content url="x"/></item></rss>';

        self::assertSame(
            '<?xml version="1.0"?><!-- c --><rss xmlns:media="http://search.yahoo.com/mrss/" version="2.0">'
            . '<item><media:content url="x"/></item></rss>',
            UndeclaredPrefixes::declaredIn($xml),
        );
    }

    public function testDeclaresEachUndeclaredPrefixInEnumOrder(): void
    {
        $xml = '<rss><item><itunes:image href="x"/><dc:creator>A</dc:creator></item></rss>';

        self::assertSame(
            '<rss xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">'
            . '<item><itunes:image href="x"/><dc:creator>A</dc:creator></item></rss>',
            UndeclaredPrefixes::declaredIn($xml),
        );
    }

    public function testLeavesADeclaredPrefixAlone(): void
    {
        $xml = '<rss xmlns:media="http://search.yahoo.com/mrss/"><media:content url="x"/></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }

    public function testLeavesAFeedWithoutPrefixesAlone(): void
    {
        $xml = '<rss><channel><title>T</title></channel></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }

    public function testIgnoresAPrefixedWordInsideText(): void
    {
        $xml = '<rss><title>media:content is a tag</title></rss>';

        self::assertSame($xml, UndeclaredPrefixes::declaredIn($xml));
    }
}

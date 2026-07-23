<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class Rss2ParserTest extends TestCase
{
    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    public function testExtractsImageUrlFromMediaEnclosureOrInlineHtml(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Media item</title>
                        <link>https://example.com/media</link>
                        <description>No inline image here.</description>
                        <media:content url="https://e/a.jpg" medium="image"/>
                    </item>
                    <item>
                        <title>Inline image item</title>
                        <link>https://example.com/inline</link>
                        <description>&lt;p&gt;Body with &lt;img src="https://e/b.jpg"&gt; inline.&lt;/p&gt;</description>
                    </item>
                    <item>
                        <title>Plain item</title>
                        <link>https://example.com/plain</link>
                        <description>Just plain text, no image at all.</description>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertCount(3, $feed->entries);
        self::assertSame('https://e/a.jpg', $feed->entries[0]->imageUrl);
        self::assertSame('https://e/b.jpg', $feed->entries[1]->imageUrl);
        self::assertNull($feed->entries[2]->imageUrl);
    }
}

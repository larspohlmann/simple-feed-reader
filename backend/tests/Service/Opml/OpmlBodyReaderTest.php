<?php

declare(strict_types=1);

namespace App\Tests\Service\Opml;

use App\Exception\InvalidOpmlException;
use App\Service\Opml\OpmlBodyReader;
use PHPUnit\Framework\TestCase;

/**
 * This parser is a security boundary — it is the one place untrusted OPML is
 * turned into a DOM — so it is tested as one.
 */
final class OpmlBodyReaderTest extends TestCase
{
    public function testReturnsTheBodyOfAWellFormedDocument(): void
    {
        $body = (new OpmlBodyReader())->read(
            '<opml version="2.0"><head/><body><outline text="x"/></body></opml>',
        );

        self::assertSame('body', $body->localName);
    }

    public function testRejectsANonOpmlRoot(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<rss version="2.0"><channel/></rss>');
    }

    public function testRejectsADocumentWithNoBody(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<opml version="2.0"><head/></opml>');
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read('<opml><body>');
    }

    public function testRejectsADoctype(): void
    {
        $this->expectException(InvalidOpmlException::class);
        (new OpmlBodyReader())->read(
            '<!DOCTYPE opml [<!ENTITY x "boom">]><opml version="2.0"><body/></opml>',
        );
    }
}

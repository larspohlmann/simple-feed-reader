<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\FeedItemImageSelector;
use PHPUnit\Framework\TestCase;

final class FeedItemImageSelectorTest extends TestCase
{
    private function rss2Item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rss = '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml . '</item></channel></rss>';
        $doc->loadXML($rss);
        $item = $doc->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    private function atomEntry(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        $atom = '<entry xmlns="http://www.w3.org/2005/Atom">' . $innerXml . '</entry>';
        $doc->loadXML($atom);
        $entry = $doc->documentElement;
        self::assertInstanceOf(\DOMElement::class, $entry);

        return $entry;
    }

    public function testAnHttpEnclosureKeepsItsPrecedenceOverAnHttpsBodyImage(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/lead.jpg" type="image/jpeg" length="0"/>');
        $body = '<p>x</p><img src="https://files.example/diagram.jpg" width="900" height="600">';

        $image = FeedItemImageSelector::fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame('http://files.example/lead.jpg', $image->url);
    }

    public function testAnHttpsEmojiInTheBodyNeverDisplacesTheFeedsLeadImage(): void
    {
        $item = $this->rss2Item(
            '<media:content url="http://site.example/lead-1200.jpg" medium="image" width="1200"/>',
        );
        $body = '<p>Hi <img src="https://s.w.org/images/core/emoji/15/72x72/1f642.png" class="wp-smiley"></p>';

        $image = FeedItemImageSelector::fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame('http://site.example/lead-1200.jpg', $image->url);
    }

    public function testFallsBackToTheHttpEnclosureWhenNoHttpsCandidateExists(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/e.jpg" type="image/jpeg" length="0"/>');

        $image = FeedItemImageSelector::fromRss2($item, '<p>no image here</p>');

        self::assertNotNull($image);
        self::assertSame('http://files.example/e.jpg', $image->url);
    }

    public function testReturnsAnHttpBodyImageWhenThatIsAllThereIs(): void
    {
        $image = FeedItemImageSelector::fromRss2(
            $this->rss2Item('<description>no media</description>'),
            '<img src="http://www.techmeme.com/x/i1.jpg" width="134" height="76">',
        );

        self::assertNotNull($image);
        self::assertSame('http://www.techmeme.com/x/i1.jpg', $image->url);
    }

    public function testReturnsNullWhenNoSourceYieldsAnImage(): void
    {
        self::assertNull(FeedItemImageSelector::fromRss2(
            $this->rss2Item('<description>nothing</description>'),
            '<p>words only</p>',
        ));
    }

    public function testKeepsNativeHttpsMediaImmediately(): void
    {
        $item = $this->rss2Item('<media:content url="https://i/big.jpg" medium="image" width="700"/>');

        $image = FeedItemImageSelector::fromRss2($item, '<img src="https://i/body.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/big.jpg', $image->url);
    }

    public function testAtomTriesEveryBodyCandidateInOrder(): void
    {
        $entry = $this->atomEntry('<title>No media here</title>');

        $image = FeedItemImageSelector::fromAtom($entry, 'http://www.w3.org/2005/Atom', [
            null,
            '<p>none</p>',
            '<img src="https://i/second.jpg">',
        ]);

        self::assertNotNull($image);
        self::assertSame('https://i/second.jpg', $image->url);
    }
}

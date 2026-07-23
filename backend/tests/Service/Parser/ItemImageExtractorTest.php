<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class ItemImageExtractorTest extends TestCase
{
    private function item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML(
            '<item xmlns:media="http://search.yahoo.com/mrss/">' . $innerXml . '</item>',
        );
        $el = $doc->documentElement;
        \assert($el instanceof \DOMElement);

        return $el;
    }

    public function testMediaThumbnailWins(): void
    {
        $item = $this->item(
            '<media:thumbnail url="https://x/thumb.jpg"/>'
            . '<media:content url="https://x/big.jpg" medium="image"/>',
        );
        self::assertSame('https://x/thumb.jpg', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentImageByMedium(): void
    {
        $item = $this->item('<media:content url="https://x/pic.jpg" medium="image"/>');
        self::assertSame('https://x/pic.jpg', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentImageByType(): void
    {
        $item = $this->item('<media:content url="https://x/pic.png" type="image/png"/>');
        self::assertSame('https://x/pic.png', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaContentNonImageIgnored(): void
    {
        $item = $this->item('<media:content url="https://x/clip.mp4" type="video/mp4"/>');
        self::assertNull(ItemImageExtractor::fromMedia($item));
    }

    public function testMediaGroupThumbnailWins(): void
    {
        $item = $this->item(
            '<media:group><media:thumbnail url="https://x/grp.jpg"/>'
            . '<media:content url="https://x/grp-big.jpg" medium="image"/></media:group>',
        );
        self::assertSame('https://x/grp.jpg', ItemImageExtractor::fromMedia($item));
    }

    public function testMediaGroupContentImage(): void
    {
        $item = $this->item(
            '<media:group><media:content url="https://x/g.png" type="image/png"/></media:group>',
        );
        self::assertSame('https://x/g.png', ItemImageExtractor::fromMedia($item));
    }

    public function testRssImageEnclosure(): void
    {
        $item = $this->item('<enclosure url="https://x/a.jpg" type="image/jpeg" length="1"/>');
        self::assertSame('https://x/a.jpg', ItemImageExtractor::fromRssEnclosure($item));
    }

    public function testRssNonImageEnclosureIgnored(): void
    {
        $item = $this->item('<enclosure url="https://x/a.mp3" type="audio/mpeg" length="1"/>');
        self::assertNull(ItemImageExtractor::fromRssEnclosure($item));
    }

    public function testFirstImgFromHtml(): void
    {
        self::assertSame(
            'https://x/inline.jpg',
            ItemImageExtractor::fromHtml('<p>hi</p><img src="https://x/inline.jpg" alt="x"> more'),
        );
    }

    public function testHtmlWithoutImgIsNull(): void
    {
        self::assertNull(ItemImageExtractor::fromHtml('<p>no image here</p>'));
        self::assertNull(ItemImageExtractor::fromHtml(null));
    }
}

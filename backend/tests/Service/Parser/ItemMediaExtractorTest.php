<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemMediaExtractor;
use App\Service\Parser\VisualMediaKind;
use PHPUnit\Framework\TestCase;

final class ItemMediaExtractorTest extends TestCase
{
    private function rssItem(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $root = '<rss xmlns:media="http://search.yahoo.com/mrss/"'
            . ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><item>'
            . $innerXml . '</item></channel></rss>';
        $doc->loadXML($root);
        $item = $doc->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    private function atomEntry(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML('<feed xmlns="http://www.w3.org/2005/Atom"><entry>' . $innerXml . '</entry></feed>');
        $entry = $doc->getElementsByTagName('entry')->item(0);
        self::assertInstanceOf(\DOMElement::class, $entry);

        return $entry;
    }

    public function testPodcastEnclosureBecomesAnAttachmentWithMimeDurationAndSize(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<enclosure url="https://cdn/ep1.mp3" type="audio/mpeg" length="4200000"/>'
            . '<itunes:duration>1:02:03</itunes:duration>',
        ));

        self::assertSame([], $bundle->media);
        self::assertCount(1, $bundle->attachments);
        $attachment = $bundle->attachments[0];
        self::assertSame('https://cdn/ep1.mp3', $attachment->url);
        self::assertSame('audio/mpeg', $attachment->mimeType);
        self::assertSame(3723, $attachment->durationInSeconds);
        self::assertSame(4200000, $attachment->sizeInBytes);
    }

    public function testMultipleTopLevelImagesBecomeMediaWithDimensions(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<media:content url="https://i/one.jpg" medium="image" width="800" height="600"/>'
            . '<media:content url="https://i/two.jpg" medium="image" width="400" height="300"/>',
        ));

        self::assertSame([], $bundle->attachments);
        self::assertCount(2, $bundle->media);
        self::assertSame('https://i/one.jpg', $bundle->media[0]->url);
        self::assertSame(VisualMediaKind::Image, $bundle->media[0]->kind);
        self::assertSame(800, $bundle->media[0]->width);
        self::assertSame(600, $bundle->media[0]->height);
        self::assertSame('https://i/two.jpg', $bundle->media[1]->url);
    }

    public function testVideoGroupYieldsAVisualWithPosterAndAPlayableAttachment(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://v/clip.mp4" medium="video" type="video/mp4" duration="90" fileSize="5000"/>'
            . '<media:thumbnail url="https://v/poster.jpg"/>'
            . '</media:group>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame(VisualMediaKind::Video, $bundle->media[0]->kind);
        self::assertSame('https://v/clip.mp4', $bundle->media[0]->url);
        self::assertSame('https://v/poster.jpg', $bundle->media[0]->previewImageUrl);

        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://v/clip.mp4', $bundle->attachments[0]->url);
        self::assertSame('video/mp4', $bundle->attachments[0]->mimeType);
        self::assertSame(90, $bundle->attachments[0]->durationInSeconds);
        self::assertSame(5000, $bundle->attachments[0]->sizeInBytes);
    }

    public function testImageGroupCollapsesToTheWidestRendition(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://i/140.jpg" medium="image" width="140"/>'
            . '<media:content url="https://i/700.jpg" medium="image" width="700"/>'
            . '</media:group>',
        ));

        self::assertCount(1, $bundle->media);
        self::assertSame('https://i/700.jpg', $bundle->media[0]->url);
    }

    public function testAtomEnclosureLinkBecomesAnAttachment(): void
    {
        $bundle = ItemMediaExtractor::extract($this->atomEntry(
            '<link rel="enclosure" type="audio/mpeg" href="https://cdn/atom.mp3" length="1000"/>',
        ));

        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://cdn/atom.mp3', $bundle->attachments[0]->url);
        self::assertSame('audio/mpeg', $bundle->attachments[0]->mimeType);
        self::assertSame(1000, $bundle->attachments[0]->sizeInBytes);
    }

    public function testAttachmentCarriesItsOwnMediaTitleWhenDeclared(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<media:content url="https://cdn/seg.mp3" type="audio/mpeg">'
            . '<media:title>Chapter two</media:title>'
            . '</media:content>',
        ));

        self::assertCount(1, $bundle->attachments);
        self::assertSame('Chapter two', $bundle->attachments[0]->title);
    }

    public function testUnknownTypelessNodeIsLeftOut(): void
    {
        $bundle = ItemMediaExtractor::extract($this->rssItem(
            '<media:content url="https://x/player-page"/>',
        ));

        self::assertSame([], $bundle->media);
        self::assertSame([], $bundle->attachments);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\FeedMediaClassifier;
use App\Service\Parser\FeedMediaKind;
use PHPUnit\Framework\TestCase;

final class FeedMediaClassifierTest extends TestCase
{
    private function element(string $xml): \DOMElement
    {
        $doc = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $doc->loadXML('<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>' . $xml . '</item></channel></rss>');
        $node = $doc->getElementsByTagName('item')->item(0)?->firstChild;
        self::assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    public function testThumbnailIsImage(): void
    {
        self::assertSame(FeedMediaKind::Image, FeedMediaClassifier::kind($this->element('<media:thumbnail url="https://i/t.jpg"/>')));
    }

    public function testAudioTypeIsAudio(): void
    {
        self::assertSame(FeedMediaKind::Audio, FeedMediaClassifier::kind($this->element('<enclosure url="https://a/x" type="audio/mpeg"/>')));
    }

    public function testVideoMediumIsVideo(): void
    {
        self::assertSame(FeedMediaKind::Video, FeedMediaClassifier::kind($this->element('<media:content url="https://v/x" medium="video"/>')));
    }

    public function testImageExtensionWithoutTypeIsImage(): void
    {
        self::assertSame(FeedMediaKind::Image, FeedMediaClassifier::kind($this->element('<media:content url="https://i/photo.jpg"/>')));
    }

    public function testAudioExtensionWithoutTypeIsAudio(): void
    {
        self::assertSame(FeedMediaKind::Audio, FeedMediaClassifier::kind($this->element('<enclosure url="https://a/ep.mp3"/>')));
    }

    public function testExplicitNonMediaTypeIsOther(): void
    {
        self::assertSame(FeedMediaKind::Other, FeedMediaClassifier::kind($this->element('<enclosure url="https://d/doc" type="application/pdf"/>')));
    }

    public function testUnknownTypelessExtensionlessIsUnknown(): void
    {
        self::assertSame(FeedMediaKind::Unknown, FeedMediaClassifier::kind($this->element('<media:content url="https://x/player"/>')));
    }
}

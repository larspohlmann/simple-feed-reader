<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Entity\ImageRendition;
use App\Service\Parser\Support\PodcastArtwork;
use PHPUnit\Framework\TestCase;

final class PodcastArtworkTest extends TestCase
{
    private function item(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $document->loadXML(
            '<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"'
            . ' xmlns:podcast="https://podcastindex.org/namespace/1.0"'
            . ' xmlns:googleplay="http://www.google.com/schemas/play-podcasts/1.0"><channel><item>'
            . $innerXml
            . '</item></channel></rss>',
        );
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    private function artworkUrl(string $innerXml): ?string
    {
        return PodcastArtwork::of($this->item($innerXml))?->url;
    }

    public function testReadsAnItunesImage(): void
    {
        self::assertSame('https://i/itunes.jpg', $this->artworkUrl('<itunes:image href="https://i/itunes.jpg"/>'));
    }

    public function testReadsAGooglePlayImage(): void
    {
        self::assertSame('https://i/play.jpg', $this->artworkUrl('<googleplay:image href="https://i/play.jpg"/>'));
    }

    public function testReadsAPodcastImageWithItsDeclaredDimensions(): void
    {
        $image = PodcastArtwork::of($this->item(
            '<podcast:image href="https://i/p.jpg" width="1400" height="1400"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/p.jpg', $image->url);
        self::assertSame(1400, $image->width);
        self::assertSame(1400, $image->height);
    }

    public function testThePodcastNamespaceBeatsItunesWhichBeatsGooglePlay(): void
    {
        self::assertSame('https://i/p.jpg', $this->artworkUrl(
            '<googleplay:image href="https://i/play.jpg"/><itunes:image href="https://i/itunes.jpg"/>'
            . '<podcast:image href="https://i/p.jpg"/>',
        ));
        self::assertSame('https://i/itunes.jpg', $this->artworkUrl(
            '<googleplay:image href="https://i/play.jpg"/><itunes:image href="https://i/itunes.jpg"/>',
        ));
    }

    public function testTheWidestPodcastImageWins(): void
    {
        self::assertSame('https://i/big.jpg', $this->artworkUrl(
            '<podcast:image href="https://i/small.jpg" width="300"/>'
            . '<podcast:image href="https://i/big.jpg" width="3000"/>',
        ));
    }

    public function testSkipsAPodcastImageThatIsAVideo(): void
    {
        self::assertSame('https://i/still.jpg', $this->artworkUrl(
            '<podcast:image href="https://i/canvas.mp4" type="video/mp4" width="3000"/>'
            . '<podcast:image href="https://i/still.jpg" type="image/jpeg" width="1400"/>',
        ));
    }

    public function testSkipsAPodcastImageMeantOnlyAsABanner(): void
    {
        self::assertSame('https://i/art.jpg', $this->artworkUrl(
            '<podcast:image href="https://i/banner.jpg" purpose="banner" width="3000"/>'
            . '<podcast:image href="https://i/art.jpg" purpose="artwork social" width="1400"/>',
        ));
    }

    public function testASocialPodcastImageQualifies(): void
    {
        self::assertSame('https://i/social.jpg', $this->artworkUrl(
            '<podcast:image href="https://i/social.jpg" purpose="social"/>',
        ));
    }

    public function testSkipsAnArtworkElementWithoutAnHref(): void
    {
        self::assertSame('https://i/play.jpg', $this->artworkUrl(
            '<podcast:image href=" "/><itunes:image/><googleplay:image href="https://i/play.jpg"/>',
        ));
    }

    public function testReadsTheWidestCandidateOfADeprecatedPodcastImagesSrcset(): void
    {
        $image = PodcastArtwork::of($this->item(
            '<podcast:images srcset="https://i/a-300.jpg 300w, https://i/a-1500.jpg 1500w"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/a-1500.jpg', $image->url);
        self::assertSame(1500, $image->width);
        self::assertEquals(
            [new ImageRendition('https://i/a-300.jpg', 300), new ImageRendition('https://i/a-1500.jpg', 1500)],
            $image->renditions,
        );
    }

    public function testAPodcastImageBeatsTheDeprecatedSrcset(): void
    {
        self::assertSame('https://i/p.jpg', $this->artworkUrl(
            '<podcast:images srcset="https://i/a-1500.jpg 1500w"/><podcast:image href="https://i/p.jpg"/>',
        ));
    }

    public function testASrcsetWithoutWidthDescriptorsFallsThroughToItunes(): void
    {
        self::assertSame('https://i/itunes.jpg', $this->artworkUrl(
            '<podcast:images srcset="https://i/a.jpg"/><itunes:image href="https://i/itunes.jpg"/>',
        ));
    }

    public function testAnUnNamespacedImageWithAnHrefIsNotArtwork(): void
    {
        self::assertNull($this->artworkUrl('<image href="https://i/plain.jpg"/>'));
    }

    public function testYieldsNothingWithoutArtwork(): void
    {
        self::assertNull($this->artworkUrl('<title>Episode</title>'));
    }
}

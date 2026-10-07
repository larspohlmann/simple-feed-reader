<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Entity\ImageRendition;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class FeedItemImageSelectorTest extends TestCase
{
    private FeedItemImageSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new FeedItemImageSelector(new ItemImageExtractor());
    }

    private function rss2Item(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rss = '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml . '</item></channel></rss>';
        $document->loadXML($rss);
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    private function atomEntry(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        $atom = '<entry xmlns="http://www.w3.org/2005/Atom">' . $innerXml . '</entry>';
        $document->loadXML($atom);
        $entry = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $entry);

        return $entry;
    }

    public function testAnHttpEnclosureKeepsItsPrecedenceOverAnHttpsBodyImage(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/lead.jpg" type="image/jpeg" length="0"/>');
        $body = '<p>x</p><img src="https://files.example/diagram.jpg" width="900" height="600">';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame('http://files.example/lead.jpg', $image->url);
    }

    public function testAnHttpsEmojiInTheBodyNeverDisplacesTheFeedsLeadImage(): void
    {
        $item = $this->rss2Item(
            '<media:content url="http://site.example/lead-1200.jpg" medium="image" width="1200"/>',
        );
        $body = '<p>Hi <img src="https://s.w.org/images/core/emoji/15/72x72/1f642.png" class="wp-smiley"></p>';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame('http://site.example/lead-1200.jpg', $image->url);
    }

    public function testFallsBackToTheHttpEnclosureWhenNoHttpsCandidateExists(): void
    {
        $item = $this->rss2Item('<enclosure url="http://files.example/e.jpg" type="image/jpeg" length="0"/>');

        $image = $this->selector->fromRss2($item, '<p>no image here</p>');

        self::assertNotNull($image);
        self::assertSame('http://files.example/e.jpg', $image->url);
    }

    public function testReturnsAnHttpBodyImageWhenThatIsAllThereIs(): void
    {
        $image = $this->selector->fromRss2(
            $this->rss2Item('<description>no media</description>'),
            '<img src="http://www.techmeme.com/x/i1.jpg" width="134" height="76">',
        );

        self::assertNotNull($image);
        self::assertSame('http://www.techmeme.com/x/i1.jpg', $image->url);
    }

    public function testReturnsNullWhenNoSourceYieldsAnImage(): void
    {
        self::assertNull($this->selector->fromRss2(
            $this->rss2Item('<description>nothing</description>'),
            '<p>words only</p>',
        ));
    }

    public function testAnRss2ItemWithOnlyPodcastArtworkTakesIt(): void
    {
        $item = $this->rss2Item(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/art.jpg"/>',
        );

        self::assertSame('https://i/art.jpg', $this->selector->fromRss2($item, '<p>show notes</p>')?->url);
    }

    public function testAMediaRssImageBeatsPodcastArtwork(): void
    {
        $item = $this->rss2Item(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/art.jpg"/>'
            . '<media:content url="https://i/media.jpg" medium="image"/>',
        );

        self::assertSame('https://i/media.jpg', $this->selector->fromRss2($item, null)?->url);
    }

    public function testACustomImageElementBeatsPodcastArtwork(): void
    {
        $item = $this->rss2Item(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/art.jpg"/>'
            . '<image url="https://i/custom.jpg"/>',
        );

        self::assertSame('https://i/custom.jpg', $this->selector->fromRss2($item, null)?->url);
    }

    public function testAnImageEnclosureBeatsPodcastArtwork(): void
    {
        $item = $this->rss2Item(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/art.jpg"/>'
            . '<enclosure url="https://i/enclosure.jpg" type="image/jpeg" length="0"/>',
        );

        self::assertSame('https://i/enclosure.jpg', $this->selector->fromRss2($item, null)?->url);
    }

    public function testThePostsOwnBodyImageBeatsPodcastArtwork(): void
    {
        $item = $this->rss2Item(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/show.jpg"/>',
        );

        $image = $this->selector->fromRss2($item, '<img src="https://i/photo.jpg">');

        self::assertSame('https://i/photo.jpg', $image?->url);
    }

    public function testAnAtomEntrysBodyImageBeatsPodcastArtwork(): void
    {
        $entry = $this->atomEntry(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/show.jpg"/>',
        );

        $image = $this->selector->fromAtom($entry, 'http://www.w3.org/2005/Atom', ['<img src="https://i/photo.jpg">']);

        self::assertSame('https://i/photo.jpg', $image?->url);
    }

    public function testAnAtomEntryWithOnlyPodcastArtworkTakesIt(): void
    {
        $entry = $this->atomEntry(
            '<itunes:image xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" href="https://i/art.jpg"/>',
        );

        $image = $this->selector->fromAtom($entry, 'http://www.w3.org/2005/Atom', []);

        self::assertSame('https://i/art.jpg', $image?->url);
    }

    public function testKeepsNativeHttpsMediaImmediately(): void
    {
        $item = $this->rss2Item('<media:content url="https://i/big.jpg" medium="image" width="700"/>');

        $image = $this->selector->fromRss2($item, '<img src="https://i/body.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/big.jpg', $image->url);
    }

    public function testAtomTriesEveryBodyCandidateInOrder(): void
    {
        $entry = $this->atomEntry('<title>No media here</title>');

        $image = $this->selector->fromAtom($entry, 'http://www.w3.org/2005/Atom', [
            null,
            '<p>none</p>',
            '<img src="https://i/second.jpg">',
        ]);

        self::assertNotNull($image);
        self::assertSame('https://i/second.jpg', $image->url);
    }

    private const string SUBSTACK_SOURCE = 'https%3A%2F%2Fsubstack-post-media.s3.amazonaws.com%2Fpublic%2Fimages'
        . '%2F10a5f3c6-6b92-48ff-8280-0cd3a9f25e41_750x1054.jpeg';

    private static function substack(string $transforms): string
    {
        return 'https://substackcdn.com/image/fetch/$s_!v2GA!,' . $transforms . '/' . self::SUBSTACK_SOURCE;
    }

    private function rss1Item(string $innerXml): \DOMElement
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
            . ' xmlns="http://purl.org/rss/1.0/" xmlns:media="http://search.yahoo.com/mrss/"><item>'
            . $innerXml . '</item></rdf:RDF>';
        $document->loadXML($rdf);
        $item = $document->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testASubstackEnclosureTakesTheBodyImagesLadder(): void
    {
        $item = $this->rss2Item(
            '<enclosure url="' . self::substack('f_auto,q_auto:good,fl_progressive:steep')
            . '" length="0" type="image/jpeg"/>',
        );
        $body = '<img src="' . self::substack('w_1456,c_limit,f_auto') . '" width="750" height="1054"'
            . ' srcset="' . self::substack('w_424,c_limit,f_auto') . ' 424w, '
            . self::substack('w_1456,c_limit,f_auto') . ' 1456w">';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertSame(self::substack('f_auto,q_auto:good,fl_progressive:steep'), $image->url);
        self::assertEquals(
            [
                new ImageRendition(self::substack('w_424,c_limit,f_auto'), 424),
                new ImageRendition(self::substack('w_1456,c_limit,f_auto'), 1456),
            ],
            $image->renditions,
        );
    }

    public function testABodyImageOfAnotherPictureLendsNoRenditions(): void
    {
        $item = $this->rss2Item('<media:content url="https://i/harbor-lighthouse.jpg" medium="image" width="700"/>');
        $body = '<img src="https://i/mountain-summit.jpg" srcset="https://i/mountain-summit-300.jpg 300w">';

        $image = $this->selector->fromRss2($item, $body);

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse.jpg', 700)], $image->renditions);
    }

    public function testABodyImageAloneKeepsItsOwnLadder(): void
    {
        $image = $this->selector->fromRss2(
            $this->rss2Item('<description>no media</description>'),
            '<img src="https://i/harbor-lighthouse-1024x683.jpg"'
            . ' srcset="https://i/harbor-lighthouse-300x200.jpg 300w">',
        );

        self::assertNotNull($image);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300x200.jpg', 300)], $image->renditions);
    }

    public function testAnAtomEnclosureTakesTheFirstBodyImagesLadder(): void
    {
        $entry = $this->atomEntry(
            '<link rel="enclosure" type="image/jpeg" href="https://i/harbor-lighthouse.jpg"/>',
        );

        $image = $this->selector->fromAtom($entry, 'http://www.w3.org/2005/Atom', [
            null,
            '<img src="https://i/harbor-lighthouse-1024x683.jpg"'
            . ' srcset="https://i/harbor-lighthouse-300x200.jpg 300w">',
        ]);

        self::assertNotNull($image);
        self::assertSame('https://i/harbor-lighthouse.jpg', $image->url);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300x200.jpg', 300)], $image->renditions);
    }

    public function testAnRss1MediaImageTakesTheBodyImagesLadder(): void
    {
        $item = $this->rss1Item('<media:content url="https://i/harbor-lighthouse.jpg" medium="image"/>');

        $image = $this->selector->fromRss1(
            $item,
            '<img src="https://i/harbor-lighthouse-1024x683.jpg"'
            . ' srcset="https://i/harbor-lighthouse-300x200.jpg 300w">',
        );

        self::assertNotNull($image);
        self::assertSame('https://i/harbor-lighthouse.jpg', $image->url);
        self::assertEquals([new ImageRendition('https://i/harbor-lighthouse-300x200.jpg', 300)], $image->renditions);
    }

    public function testAnRss1ItemFallsBackToItsBodyImage(): void
    {
        $image = $this->selector->fromRss1($this->rss1Item('<title>t</title>'), '<img src="https://i/body.jpg">');

        self::assertSame('https://i/body.jpg', $image?->url);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Enum\CommentsLoad;
use App\Service\Parser\ParsedEntry;
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

    private function parseSingleItem(string $itemXml): ParsedEntry
    {
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $document = $this->document(<<<XML
            <rss version="2.0"
                 xmlns:wfw="http://wellformedweb.org/CommentAPI/"
                 xmlns:slash="http://purl.org/rss/1.0/modules/slash/">
              <channel><title>Blog</title>{$itemXml}</channel>
            </rss>
            XML);

        return (new Rss2Parser())->parse($document)->entries[0];
    }

    public function testCarriesPodcastEnclosureIntoAttachments(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                <channel>
                    <title>Cast</title>
                    <link>https://example.com/</link>
                    <item>
                        <title>Episode 1</title>
                        <link>https://example.com/ep1</link>
                        <enclosure url="https://cdn/ep1.mp3" type="audio/mpeg" length="4200000"/>
                        <itunes:duration>1:02:03</itunes:duration>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        $bundle = $feed->entries[0]->media->mediaBundle;
        self::assertNotNull($bundle);
        self::assertCount(1, $bundle->attachments);
        $attachment = $bundle->attachments[0];
        self::assertSame('https://cdn/ep1.mp3', $attachment->url);
        self::assertSame('audio/mpeg', $attachment->mimeType);
        self::assertSame(3723, $attachment->durationInSeconds);
    }

    public function testExtractsImageUrlFromMediaEnclosureOrInlineHtml(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
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
                        <description>&lt;p&gt;&lt;img src="https://e/b.jpg"&gt;&lt;/p&gt;</description>
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
        self::assertSame('https://e/a.jpg', $feed->entries[0]->media->image?->url);
        self::assertSame('https://e/b.jpg', $feed->entries[1]->media->image?->url);
        self::assertNull($feed->entries[2]->media->image);
    }

    public function testReadsACustomImageBigElementWhenTheItemHasNoStandardImage(): void
    {
        // A utopia.de-shaped item: no media:*, no enclosure, no inline <img> —
        // only the non-standard <image>/<image_big> item elements. The larger
        // variant wins, and its declared dimensions are kept.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Utopia</title>
                    <link>https://utopia.de/</link>
                    <description>Nachhaltigkeit</description>
                    <item>
                        <title>Custom image item</title>
                        <link>https://utopia.de/ratgeber/x</link>
                        <description>Plain text, no inline image at all.</description>
                        <image url="https://images.utopia.de/x/w:194/h:126/small.jpg" width="194" height="126"/>
                        <image_big url="https://images.utopia.de/x/w:640/h:300/big.jpg" width="640" height="300"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertCount(1, $feed->entries);
        $image = $feed->entries[0]->media->image;
        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:640/h:300/big.jpg', $image->url);
        self::assertSame(640, $image->width);
        self::assertSame(300, $image->height);
    }

    public function testMediaImageWinsWhenEveryImageSourceIsPresent(): void
    {
        // Media RSS outranks an enclosure, a custom <image_big> and an inline
        // <img> alike: a dedicated feed image must never lose to a body picture.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Every source item</title>
                        <link>https://example.com/every</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <enclosure url="https://e/enclosure.jpg" type="image/jpeg"/>
                        <media:content url="https://e/media.jpg" medium="image" width="800"/>
                        <image_big url="https://e/custom.jpg" width="640"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('https://e/media.jpg', $feed->entries[0]->media->image?->url);
    }

    public function testEnclosureWinsOverCustomImageAndInlineImg(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Enclosure over rest</title>
                        <link>https://example.com/enc</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <enclosure url="https://e/enclosure.jpg" type="image/jpeg"/>
                        <image_big url="https://e/custom.jpg" width="640"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('https://e/enclosure.jpg', $feed->entries[0]->media->image?->url);
    }

    public function testCustomImageElementWinsOverAnInlineImg(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Custom over inline</title>
                        <link>https://example.com/custom-over-inline</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <image_big url="https://e/custom.jpg" width="640" height="300"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        $image = $feed->entries[0]->media->image;
        self::assertNotNull($image);
        self::assertSame('https://e/custom.jpg', $image->url);
        self::assertSame(640, $image->width);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
        // Both the feed and the item title carry entity-escaped HTML: the <em>
        // markup and the &#8220;/&#8221; curly-quote references that real feeds
        // ship. The XML reader decodes those one level, leaving literal tags and
        // references that must not surface in the reader.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>The &lt;em&gt;Weekly&lt;/em&gt; Review</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>An &lt;em&gt;Odyssey&lt;/em&gt; for Our Own Time</title>
                        <link>https://example.com/odyssey</link>
                    </item>
                    <item>
                        <title>&amp;#8220;Datatype&amp;#8221; is an OpenType variable font</title>
                        <link>https://example.com/datatype</link>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('The Weekly Review', $feed->title);
        self::assertSame('An Odyssey for Our Own Time', $feed->entries[0]->title);
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            $feed->entries[1]->title,
        );
    }

    public function testPrefersTheRealLinkOverASelfReferencingAtomLink(): void
    {
        // Al Jazeera and many other RSS 2.0 feeds open the channel with an
        // <atom:link rel="self"/>. It shares the local name "link" and carries
        // no text, and returning on that first match left those feeds with no
        // site URL at all.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
                <channel>
                    <atom:link href="https://example.com/feed.xml" rel="self" type="application/rss+xml"/>
                    <title>Example</title>
                    <link>https://example.com</link>
                    <description>Example feed</description>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertSame('https://example.com', (new Rss2Parser())->parse($this->document($xml))->siteUrl);
    }

    public function testReadsTheChannelImage(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <image><url>https://example.com/logo.png</url></image>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertSame('https://example.com/logo.png', (new Rss2Parser())->parse($this->document($xml))->imageUrl);
    }

    public function testAChannelWithoutAnImageParsesToNull(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertNull((new Rss2Parser())->parse($this->document($xml))->imageUrl);
    }

    public function testWordPressCommentFeedIsAManualCommentsFeed(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Post</title>
              <link>https://blog.example/post/</link>
              <comments>https://blog.example/post/#comments</comments>
              <wfw:commentRss>https://blog.example/post/feed/</wfw:commentRss>
              <slash:comments>3</slash:comments>
            </item>
            XML);

        self::assertSame('https://blog.example/post/#comments', $entry->discussion->url);
        self::assertSame('https://blog.example/post/feed/', $entry->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $entry->discussion->commentsLoad);
    }

    public function testCommentsPageWithoutFeedIsADiscussionPageOnly(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Show HN</title>
              <link>https://project.example/</link>
              <comments>https://news.ycombinator.com/item?id=1</comments>
            </item>
            XML);

        self::assertSame('https://news.ycombinator.com/item?id=1', $entry->discussion->url);
        self::assertFalse($entry->discussion->hasCommentsFeed());
    }

    public function testSlashCommentsCountIsNeverTakenForAUrl(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Post</title>
              <link>https://blog.example/post/</link>
              <slash:comments>0</slash:comments>
            </item>
            XML);

        self::assertNull($entry->discussion->url);
    }
}

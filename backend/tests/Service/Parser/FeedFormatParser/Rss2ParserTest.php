<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\FeedFormatParser;

use App\Enum\CommentsLoad;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Tests\Support\FeedFormatParsers;
use PHPUnit\Framework\TestCase;

final class Rss2ParserTest extends TestCase
{
    private function parseSingleItem(string $itemXml): ParsedEntryModel
    {
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $xml = <<<XML
            <rss version="2.0"
                 xmlns:wfw="http://wellformedweb.org/CommentAPI/"
                 xmlns:slash="http://purl.org/rss/1.0/modules/slash/">
              <channel><title>Blog</title>{$itemXml}</channel>
            </rss>
            XML;

        return FeedFormatParsers::feed($xml)->entries[0];
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

        $feed = FeedFormatParsers::feed($xml);

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

        $feed = FeedFormatParsers::feed($xml);

        self::assertCount(3, $feed->entries);
        self::assertSame('https://e/a.jpg', $feed->entries[0]->media->image?->url);
        self::assertSame('https://e/b.jpg', $feed->entries[1]->media->image?->url);
        self::assertNull($feed->entries[2]->media->image);
    }

    public function testReadsACustomImageBigElementWhenTheItemHasNoStandardImage(): void
    {
        // Only the non-standard <image>/<image_big> item elements: the larger variant wins, with its dimensions.
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

        $feed = FeedFormatParsers::feed($xml);

        self::assertCount(1, $feed->entries);
        $image = $feed->entries[0]->media->image;
        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:640/h:300/big.jpg', $image->url);
        self::assertSame(640, $image->width);
        self::assertSame(300, $image->height);
    }

    public function testMediaImageWinsWhenEveryImageSourceIsPresent(): void
    {
        // A dedicated feed image must never lose to a body picture.
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

        $feed = FeedFormatParsers::feed($xml);

        self::assertSame('https://e/media.jpg', $feed->entries[0]->media->image?->url);
    }

    public function testEnclosureWinsOverCustomImageAndInlineImg(): void
    {
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

        $feed = FeedFormatParsers::feed($xml);

        self::assertSame('https://e/enclosure.jpg', $feed->entries[0]->media->image?->url);
    }

    public function testCustomImageElementWinsOverAnInlineImg(): void
    {
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

        $feed = FeedFormatParsers::feed($xml);

        $image = $feed->entries[0]->media->image;
        self::assertNotNull($image);
        self::assertSame('https://e/custom.jpg', $image->url);
        self::assertSame(640, $image->width);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
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

        $feed = FeedFormatParsers::feed($xml);

        self::assertSame('The Weekly Review', $feed->title);
        self::assertSame('An Odyssey for Our Own Time', $feed->entries[0]->title);
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            $feed->entries[1]->title,
        );
    }

    public function testPrefersTheRealLinkOverASelfReferencingAtomLink(): void
    {
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

        self::assertSame('https://example.com', FeedFormatParsers::feed($xml)->siteUrl);
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

        $feed = FeedFormatParsers::feed($xml);

        self::assertSame('https://example.com/logo.png', $feed->imageUrl);
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

        self::assertNull(FeedFormatParsers::feed($xml)->imageUrl);
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
        self::assertNull($entry->discussion->commentsFeedUrl);
    }

    public function testAWhitespacePaddedCommentsPageIsTrimmed(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Post</title>
              <comments>
                https://blog.example/post/#comments
              </comments>
            </item>
            XML);

        self::assertSame('https://blog.example/post/#comments', $entry->discussion->url);
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

    public function testAPlainTextDescriptionKeepsItsLineBreaks(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Summer set</title>
              <description>Track list :
            1.Idaishoy
            2.Silver Galaxy</description>
            </item>
            XML);

        self::assertSame('<p>Track list :<br>1.Idaishoy<br>2.Silver Galaxy</p>', $entry->contentHtml);
    }

    public function testAHtmlDescriptionWithPastedTextKeepsItsParagraphBreaks(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Storm</title>
              <description>&lt;p&gt;Rain fell all night.

            (Photo: Jane Doe)&lt;/p&gt;</description>
            </item>
            XML);

        self::assertSame('<p>Rain fell all night.<br><br>(Photo: Jane Doe)</p>', $entry->contentHtml);
    }

    public function testTheBodyImageComesFromContentEncodedBeforeTheDescription(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:content="http://purl.org/rss/1.0/modules/content/"><title>Both</title>'
            . '<description><![CDATA[<img src="https://img.example.com/description.jpg" alt="">]]></description>'
            . '<content:encoded><![CDATA[<img src="https://img.example.com/content.jpg" alt="">]]></content:encoded>'
            . '</item>',
        );

        self::assertSame('https://img.example.com/content.jpg', $entry->media->image?->url);
    }

    public function testAuthorWinsOverDublinCoreCreator(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/"><title>By</title>'
            . '<dc:creator>Creator</dc:creator><author>Author</author></item>',
        );

        self::assertSame('Author', $entry->author);
    }

    public function testPubDateWinsOverDublinCoreDate(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/"><title>When</title>'
            . '<dc:date>2026-01-01T00:00:00Z</dc:date><pubDate>Tue, 06 Oct 2026 10:00:00 +0000</pubDate></item>',
        );

        self::assertSame('2026-10-06T10:00:00+00:00', $entry->publishedAt?->format(DATE_ATOM));
    }

    public function testMediaDescriptionFillsAnItemWithoutABody(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item xmlns:media="http://search.yahoo.com/mrss/">
              <title>T</title><link>https://example.com/a</link>
              <media:description>Described</media:description>
            </item>
            XML);

        self::assertSame('<p>Described</p>', $entry->contentHtml);
    }

    public function testDescriptionWinsOverMediaDescription(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item xmlns:media="http://search.yahoo.com/mrss/">
              <title>T</title><link>https://example.com/a</link>
              <description>&lt;p&gt;Body&lt;/p&gt;</description>
              <media:description>Described</media:description>
            </item>
            XML);

        self::assertSame('<p>Body</p>', $entry->contentHtml);
    }

    public function testDescriptionIsReadInADefaultNamespacedRssDocument(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0" xmlns="http://backend.userland.com/rss2">
              <channel><title>Blog</title>
                <item><title>T</title><link>https://example.com/a</link>
                  <description>&lt;p&gt;Body&lt;/p&gt;</description>
                </item>
              </channel>
            </rss>
            XML);

        self::assertSame('Blog', $feed->title);
        self::assertSame('<p>Body</p>', $feed->entries[0]->contentHtml);
    }

    public function testAnExtensionTitleBeforeTheTitleDoesNotShadowIt(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:media="http://search.yahoo.com/mrss/"'
            . ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">'
            . '<media:title>Media</media:title><itunes:title>Itunes</itunes:title><title>Core</title></item>',
        );

        self::assertSame('Core', $entry->title);
    }

    public function testAnItunesAuthorBeforeTheAuthorDoesNotShadowIt(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><title>By</title>'
            . '<itunes:author>Itunes</itunes:author><author>Core</author></item>',
        );

        self::assertSame('Core', $entry->author);
    }

    public function testAPrefixedLinkBeforeTheLinkDoesNotShadowIt(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:atom="http://www.w3.org/2005/Atom"><title>T</title>'
            . '<atom:link>https://example.com/self</atom:link><link>https://example.com/a</link></item>',
        );

        self::assertSame('https://example.com/a', $entry->url);
    }

    public function testAPrefixedGuidBeforeTheGuidDoesNotShadowIt(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:other="urn:example:other"><title>T</title>'
            . '<other:guid>other-guid</other:guid><guid>core-guid</guid></item>',
        );

        self::assertSame('core-guid', $entry->guid);
    }

    public function testDublinCoreDescriptionFillsAnItemWithoutADescription(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/"><title>T</title>'
            . '<dc:description>&lt;p&gt;Dublin&lt;/p&gt;</dc:description></item>',
        );

        self::assertSame('<p>Dublin</p>', $entry->contentHtml);
    }

    public function testDescriptionWinsOverDublinCoreDescription(): void
    {
        $entry = $this->parseSingleItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/"><title>T</title>'
            . '<dc:description>Dublin</dc:description><description>&lt;p&gt;Body&lt;/p&gt;</description></item>',
        );

        self::assertSame('<p>Body</p>', $entry->contentHtml);
    }

    public function testAPrefixedChannelTitleBeforeTheTitleDoesNotShadowIt(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
              <channel><itunes:title>Itunes</itunes:title><title>Core</title>
                <item><title>T</title><link>https://example.com/a</link></item>
              </channel>
            </rss>
            XML);

        self::assertSame('Core', $feed->title);
    }

    public function testAChannelInAnotherNamespaceIsNotTheFeedsChannel(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0">
              <channel xmlns="urn:example:other"><title>Decoy</title></channel>
              <channel><title>Core</title></channel>
            </rss>
            XML);

        self::assertSame('Core', $feed->title);
    }

    public function testANestedChannelIsNotTheFeedsChannel(): void
    {
        $feed = FeedFormatParsers::feed(<<<'XML'
            <rss version="2.0" xmlns:x="urn:example:other">
              <x:meta><channel><title>Decoy</title></channel></x:meta>
              <channel><title>Core</title></channel>
            </rss>
            XML);

        self::assertSame('Core', $feed->title);
    }

    public function testADocumentWithoutAChannelIsAParseError(): void
    {
        $document = new \DOMDocument();
        $document->loadXML('<rss version="2.0"/>');

        $this->expectException(FeedParseException::class);

        FeedFormatParsers::rss2()->parseFeed($document, []);
    }

    public function testAnItemOutsideTheChannelIsNotAnEntry(): void
    {
        self::assertSame(['Core'], FeedFormatParsers::entryTitles(<<<'XML'
            <rss version="2.0">
              <item><title>Decoy</title></item>
              <channel><title>Blog</title><item><title>Core</title></item></channel>
            </rss>
            XML));
    }

    public function testAnItemUnderAnotherRootChildIsNotAnEntry(): void
    {
        self::assertSame(['Core'], FeedFormatParsers::entryTitles(<<<'XML'
            <rss version="2.0" xmlns:x="urn:example:other">
              <x:meta><item><title>Decoy</title></item></x:meta>
              <channel><title>Blog</title><item><title>Core</title></item></channel>
            </rss>
            XML));
    }

    public function testAnItemOfANestedChannelIsNotAnEntry(): void
    {
        self::assertSame(['Core'], FeedFormatParsers::entryTitles(<<<'XML'
            <rss version="2.0">
              <section><channel><item><title>Decoy</title></item></channel></section>
              <channel><title>Blog</title><item><title>Core</title></item></channel>
            </rss>
            XML));
    }

    public function testAnItemInAnotherNamespaceIsNotAnEntry(): void
    {
        self::assertSame(['Core'], FeedFormatParsers::entryTitles(<<<'XML'
            <rss version="2.0">
              <channel><title>Blog</title>
                <item xmlns="urn:example:other"><title>Decoy</title></item>
                <item><title>Core</title></item>
              </channel>
            </rss>
            XML));
    }
}

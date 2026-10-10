<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\FeedParser;
use App\Tests\Support\FeedFormatParsers;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    use ReadsFixtures;

    private function parser(): FeedParser
    {
        return new FeedParser(new FeedParserFactory(FeedFormatParsers::all()));
    }


    public function testParsesRss2Basic(): void
    {
        $feed = $this->parser()->parse($this->fixture('feeds/rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
        self::assertSame('https://blog.example.com/', $feed->siteUrl);
        self::assertSame('News from Example', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('tag:blog.example.com,2026:announcement', $first->guid);
        // "<Announcement>" goes with the "<em>" markup: escaped HTML and literal brackets look alike once decoded.
        self::assertSame('Big & More', $first->title);
        self::assertSame('https://blog.example.com/announcement', $first->url);
        self::assertSame('Jane Doe', $first->author);
        self::assertSame('Short teaser text.', $first->summary);
        self::assertStringContainsString('<strong>story</strong>', (string) $first->contentHtml);
        // The fixture's +02:00 pubDate, as the same instant in UTC.
        self::assertSame('2026-07-20T06:30:00+00:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://blog.example.com/second', $second->guid);
        self::assertNull($second->summary);
        self::assertStringContainsString('Description-only body', (string) $second->contentHtml);
    }

    public function testASoundCloudFeedGivesEachTrackItsArtworkAndTheFeedItsImage(): void
    {
        $feed = $this->parser()->parse($this->fixture('soundcloud/sounds.rss'));

        self::assertSame('https://i1.sndcdn.com/avatars-CJw9fKUiJYURN68j-qm5fdw-original.jpg', $feed->imageUrl);
        self::assertSame(
            [
                'https://i1.sndcdn.com/artworks-h6jscIjSd8tNYwJW-XyQFNw-t3000x3000.jpg',
                'https://i1.sndcdn.com/artworks-pX3KpzZaFxY74f6A-qT8DAw-t3000x3000.png',
            ],
            array_map(static fn ($entry) => $entry->media->image?->url, $feed->entries),
        );
    }

    public function testAnEpisodeWithoutArtworkOfItsOwnTakesTheShowsAndOtherItemsDoNot(): void
    {
        $feed = $this->parser()->parse(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                <channel>
                    <title>Show</title>
                    <itunes:image href="https://i/show.jpg"/>
                    <item><title>Audio</title><enclosure url="https://c/a.mp3" type="audio/mpeg"/></item>
                    <item><title>Video</title><enclosure url="https://c/v.mp4"/></item>
                    <item>
                        <title>Own art</title><enclosure url="https://c/b.mp3" type="audio/mpeg"/>
                        <itunes:image href="https://i/episode.jpg"/>
                    </item>
                    <item><title>Text post</title><description>words</description></item>
                    <item><title>Handout</title><enclosure url="https://c/notes.pdf" type="application/pdf"/></item>
                </channel>
            </rss>
            XML);

        self::assertSame(
            ['https://i/show.jpg', 'https://i/show.jpg', 'https://i/episode.jpg', null, null],
            array_map(static fn ($entry) => $entry->media->image?->url, $feed->entries),
        );
    }

    public function testAnAtomEpisodeWithoutArtworkTakesTheShows(): void
    {
        $feed = $this->parser()->parse(<<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                <title>Show</title>
                <itunes:image href="https://i/show.jpg"/>
                <entry>
                    <title>Episode</title><id>urn:ep:1</id>
                    <link rel="enclosure" type="audio/mpeg" href="https://c/a.mp3"/>
                </entry>
            </feed>
            XML);

        self::assertSame('https://i/show.jpg', $feed->entries[0]->media->image?->url);
    }

    public function testMissingGuidFallsBackToHashAndBrokenDateBecomesNull(): void
    {
        $feed = $this->parser()->parse($this->fixture('feeds/rss2-no-guid.xml'));

        self::assertCount(2, $feed->entries);
        $first = $feed->entries[0];
        self::assertSame(
            'urn:sfr:' . hash('sha256', 'https://noguid.example.com/post-1|Post without guid'),
            $first->guid,
        );
        self::assertNull($first->publishedAt);
        self::assertNotSame($feed->entries[1]->guid, $first->guid);
    }

    public function testRejectsNonXml(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('this is { not xml');
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('');
    }

    public function testRejectsWhitespaceOnlyBody(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse("  \n\t ");
    }

    public function testParsesFeedPrecededByBlankLines(): void
    {
        $feed = $this->parser()->parse("\n\n" . $this->fixture('feeds/rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
        self::assertCount(2, $feed->entries);
    }

    public function testParsesFeedPrecededByUtf8Bom(): void
    {
        $feed = $this->parser()->parse("\u{FEFF}" . $this->fixture('feeds/atom-basic.xml'));

        self::assertSame('Atom Example', $feed->title);
        self::assertCount(2, $feed->entries);
    }

    public function testParsesFeedPrecededByBomAndBlankLines(): void
    {
        $feed = $this->parser()->parse("\u{FEFF}\r\n \n" . $this->fixture('feeds/rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
    }

    public function testRejectsBodyOfNothingButABom(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse("\u{FEFF}");
    }

    public function testRejectsBodyOfNothingButABomAndBlankLines(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse("\u{FEFF}\r\n  \n");
    }

    public function testStripsIllegalControlCharactersBeforeParsing(): void
    {
        $feed = $this->parser()->parse(
            "<?xml version=\"1.0\"?><rss version=\"2.0\"><channel>"
            . "<title>Konkret\x1DFeed</title><link>https://konkret.example.com/</link>"
            . "<item><title>First\x1DItem</title><link>https://konkret.example.com/1</link>"
            . '<guid>https://konkret.example.com/1</guid></item></channel></rss>',
        );

        self::assertSame('KonkretFeed', $feed->title);
        self::assertCount(1, $feed->entries);
        self::assertSame('FirstItem', $feed->entries[0]->title);
    }

    /**
     * The doctype guard runs after the load, so only the load flags (LIBXML_NONET, no LIBXML_DTDLOAD or LIBXML_NOENT)
     * keep a feed from making the parser open a connection of its choosing.
     */
    public function testDoesNotFetchAnExternalDtdOverTheNetwork(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, sprintf('listener failed: %s', $errorMessage));
        $address = (string) stream_socket_get_name($listener, false);

        try {
            $this->parser()->parse(
                '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://' . $address . '/feed.dtd">'
                . '<rss version="2.0"><channel><title>x</title><link>y</link></channel></rss>',
            );
            self::fail('A document declaring a DTD must be rejected');
        } catch (FeedParseException) {
            // The doctype guard rejects it; what this test asserts is below.
        }

        $connection = @stream_socket_accept($listener, 0);
        fclose($listener);

        self::assertFalse($connection, 'The parser must not open a connection a feed asked for');
    }

    public function testRejectsDocumentsDeclaringADtd(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY z "zz">]>'
            . '<rss version="2.0"><channel><title>&z;</title><link>x</link></channel></rss>',
        );
    }

    public function testRejectsEntityExpansionBomb(): void
    {
        $bomb = /** @lang TEXT */ '<?xml version="1.0"?><!DOCTYPE rss ['
            . '<!ENTITY a "AAAAAAAAAA"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
            . ']><rss version="2.0"><channel><title>&d;</title><link>x</link></channel></rss>';

        $this->expectException(FeedParseException::class);
        $this->parser()->parse($bomb);
    }

    public function testDoesNotResolveExternalEntities(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<rss version="2.0"><channel><title>&x;</title><link>y</link></channel></rss>',
        );
    }

    public function testRejectsUnknownRootElement(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(/** @lang TEXT */ '<?xml version="1.0"?><html><body>nope</body></html>');
    }

    public function testParsesAtom(): void
    {
        $feed = $this->parser()->parse($this->fixture('feeds/atom-basic.xml'));

        self::assertSame('Atom Example', $feed->title);
        self::assertSame('https://atom.example.com/', $feed->siteUrl);
        self::assertSame('An atom feed', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('urn:uuid:1225c695-cfb8-4ebb-aaaa-80da344efa6a', $first->guid);
        self::assertSame('https://atom.example.com/one', $first->url);
        self::assertSame('Ada Lovelace', $first->author);
        self::assertSame('Plain text teaser.', $first->summary);
        self::assertSame('<p>Escaped <em>html</em> body.</p>', $first->contentHtml);
        self::assertSame('2026-07-19T18:30:02+00:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://atom.example.com/two', $second->url);
        self::assertStringContainsString('<strong>xhtml</strong>', (string) $second->contentHtml);
        self::assertSame('2026-07-18T12:00:00+00:00', $second->publishedAt?->format(DATE_ATOM));
    }

    public function testParsesAtom03Dialect(): void
    {
        $feed = $this->parser()->parse($this->fixture('feeds/atom-03-basic.xml'));

        self::assertSame('Atom 0.3 Example', $feed->title);
        self::assertSame('https://atom03.example.com/', $feed->siteUrl);
        self::assertSame('An old-school atom feed', $feed->description); // <tagline>, not <subtitle>
        self::assertCount(1, $feed->entries);

        $entry = $feed->entries[0];
        self::assertSame('tag:atom03.example.com,2026:first', $entry->guid);
        self::assertSame('First 0.3 Entry', $entry->title);
        self::assertSame('https://atom03.example.com/first', $entry->url);
        self::assertSame('Old Timer', $entry->author);
        self::assertSame('A teaser from the 0.3 dialect.', $entry->summary);
        self::assertStringContainsString('<em>html</em>', (string) $entry->contentHtml);
        // Atom 0.3 dates its entries with <issued>, not <published>/<updated>.
        self::assertSame('2026-07-16T09:30:00+00:00', $entry->publishedAt?->format(DATE_ATOM));
    }

    public function testRejectsAtomWithNeitherTitleNorEntries(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>');
    }

    public function testRejectsUnknownAtomNamespace(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><feed xmlns="http://example.com/not-atom"><title>x</title></feed>',
        );
    }

    public function testParsesRss1(): void
    {
        $feed = $this->parser()->parse($this->fixture('feeds/rss1-basic.xml'));

        self::assertSame('RSS 1.0 Example', $feed->title);
        self::assertCount(1, $feed->entries);

        $item = $feed->entries[0];
        self::assertSame('https://rss1.example.com/item-1', $item->guid);
        self::assertSame('Grace Hopper', $item->author);
        self::assertStringContainsString('First RDF body', (string) $item->contentHtml);
        self::assertSame('2026-07-17T08:00:00+00:00', $item->publishedAt?->format(DATE_ATOM));
    }

    public function testAnUndeclaredNamespacePrefixDoesNotFailTheFeed(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Loose</title><itunes:author>A</itunes:author>'
            . '<item><title>One</title><link>https://loose.example.com/1</link><media:thumbnail url="x"/></item>'
            . '</channel></rss>',
        );

        self::assertSame('Loose', $feed->title);
        self::assertSame(['One'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAMalformedItemAfterGoodOnesFailsTheWholeFeed(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Broken</title>'
            . '<item><title>Good</title><link>https://broken.example.com/1</link></item>'
            . '<item><title>Bad<link>https://broken.example.com/2</link></item>'
            . '</channel></rss>',
        );
    }

    public function testATruncatedBodyFailsTheWholeFeed(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Cut</title>'
            . '<item><title>Good</title><link>https://cut.example.com/1</link></item><item><title>Ha',
        );
    }

    public function testChannelMetadataAfterTheItemsStillCounts(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel>'
            . '<item><title>One</title><link>https://late.example.com/1</link></item>'
            . '<title><![CDATA[Late & Titled]]></title><!-- note --><link>https://late.example.com/</link>'
            . '<image><url>https://late.example.com/logo.png</url></image>'
            . '</channel></rss>',
        );

        self::assertSame('Late & Titled', $feed->title);
        self::assertSame('https://late.example.com/', $feed->siteUrl);
        self::assertSame('https://late.example.com/logo.png', $feed->imageUrl);
        self::assertCount(1, $feed->entries);
    }

    public function testAnItemInsideAnExtensionElementOfTheChannelIsNotAnEntry(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Nested</title>'
            . '<section><item><title>Deep</title><link>https://nested.example.com/1</link></item></section>'
            . '<item><title>Shown</title><link>https://nested.example.com/2</link></item></channel></rss>',
        );

        self::assertSame(['Shown'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAnEmptyRssRootIsAFeedWithoutAChannel(): void
    {
        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessage('RSS document without <channel>');
        $this->parser()->parse('<?xml version="1.0"?><rss version="2.0"/>');
    }

    public function testAnAtomEntryOutsideTheFeedRootLevelIsNotAnEntry(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title>'
            . '<wrapper><entry><title>Hidden</title><link href="https://a.example.com/h"/></entry></wrapper>'
            . '<entry><title>Shown</title><link href="https://a.example.com/s"/></entry></feed>',
        );

        self::assertSame(['Shown'], array_map(static fn ($entry) => $entry->title, $feed->entries));
    }

    public function testAnAtomXhtmlContentKeepsItsMarkupAndNamespace(): void
    {
        $feed = $this->parser()->parse(
            '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:x="http://www.w3.org/1999/xhtml">'
            . '<title>Atom</title><entry><title>E</title><link href="https://a.example.com/e"/>'
            . '<content type="xhtml"><x:div><x:p>Hi <x:b>there</x:b></x:p></x:div></content></entry></feed>',
        );

        self::assertStringContainsString('<x:b>there</x:b>', (string) $feed->entries[0]->contentHtml);
    }

    public function testABodyCutOffBetweenItemsFailsTheWholeFeed(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse(
            '<?xml version="1.0"?><rss version="2.0"><channel><title>Cut</title>'
            . '<item><title>Good</title><link>https://cut.example.com/1</link></item><link>https://cut',
        );
    }

    public function testAnEarlierLibxmlFailureInTheProcessDoesNotFailTheNextFeed(): void
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            new \DOMDocument()->loadXML('<broken');
            $feed = $this->parser()->parse($this->fixture('feeds/rss2-basic.xml'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        self::assertSame('Example Tech Blog', $feed->title);
    }

    public function testAFailedParseRestoresTheLibxmlErrorModeItFound(): void
    {
        $previousErrorMode = libxml_use_internal_errors(false);
        try {
            $this->parser()->parse('<rss><channel>');
            self::fail('A truncated document must be rejected');
        } catch (FeedParseException) {
            self::assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    public function testAParseLeavesNoLibxmlErrorsBehind(): void
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $this->parser()->parse(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Loose</title><itunes:author>A</itunes:author>'
                . '</channel></rss>',
            );
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    public function testALatin1FeedIsDecodedByItsDeclaredEncoding(): void
    {
        $feed = $this->parser()->parse(
            "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><rss version=\"2.0\"><channel><title>Gr\xFC\xDFe</title>"
            . "<item><title>\xC4pfel</title><link>https://latin1.example.com/1</link></item></channel></rss>",
        );

        self::assertSame('Grüße', $feed->title);
        self::assertSame('Äpfel', $feed->entries[0]->title);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\AtomParser;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    private function parser(): FeedParser
    {
        return new FeedParser(new Rss2Parser(), new AtomParser(), new Rss1Parser());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/feeds/' . $name);
    }

    public function testParsesRss2Basic(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
        self::assertSame('https://blog.example.com/', $feed->siteUrl);
        self::assertSame('News from Example', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('tag:blog.example.com,2026:announcement', $first->guid);
        self::assertSame('Big <Announcement> & More', $first->title);
        self::assertSame('https://blog.example.com/announcement', $first->url);
        self::assertSame('Jane Doe', $first->author);
        self::assertSame('Short teaser text.', $first->summary);
        self::assertStringContainsString('<strong>story</strong>', (string) $first->contentHtml);
        self::assertSame('2026-07-20T08:30:00+02:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://blog.example.com/second', $second->guid);
        self::assertNull($second->summary);
        self::assertStringContainsString('Description-only body', (string) $second->contentHtml);
    }

    public function testMissingGuidFallsBackToHashAndBrokenDateBecomesNull(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-no-guid.xml'));

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
        $bomb = '<?xml version="1.0"?><!DOCTYPE rss ['
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
        $this->parser()->parse('<?xml version="1.0"?><html><body>nope</body></html>');
    }

    public function testParsesAtom(): void
    {
        $feed = $this->parser()->parse($this->fixture('atom-basic.xml'));

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

    public function testParsesRss1(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss1-basic.xml'));

        self::assertSame('RSS 1.0 Example', $feed->title);
        self::assertCount(1, $feed->entries);

        $item = $feed->entries[0];
        self::assertSame('https://rss1.example.com/item-1', $item->guid);
        self::assertSame('Grace Hopper', $item->author);
        self::assertStringContainsString('First RDF body', (string) $item->contentHtml);
        self::assertSame('2026-07-17T08:00:00+00:00', $item->publishedAt?->format(DATE_ATOM));
    }
}

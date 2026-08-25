<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\WordPressJsonParser;
use PHPUnit\Framework\TestCase;

final class WordPressJsonParserTest extends TestCase
{
    private const POST = <<<'JSON'
        [
          {
            "id": 101,
            "date_gmt": "2026-08-20T14:23:15",
            "guid": { "rendered": "https://site.example/?p=101" },
            "link": "https://site.example/hello-world/",
            "title": { "rendered": "Hello &amp; <em>welcome</em>" },
            "content": { "rendered": "<p>Full body.</p>" },
            "excerpt": { "rendered": "<p>Short.</p>" },
            "jetpack_featured_media_url": "https://site.example/img.jpg"
          }
        ]
        JSON;

    private function parse(string $body): \App\Service\Parser\ParsedFeed
    {
        return (new WordPressJsonParser())->parse($body);
    }

    public function testMapsAFullPost(): void
    {
        $entry = $this->parse(self::POST)->entries[0];

        self::assertSame('https://site.example/?p=101', $entry->guid);
        self::assertSame('https://site.example/hello-world/', $entry->url);
        self::assertSame('Hello & welcome', $entry->title);
        self::assertSame('<p>Full body.</p>', $entry->contentHtml);
        self::assertSame('<p>Short.</p>', $entry->summary);
        self::assertNull($entry->author);
        self::assertNotNull($entry->image);
        self::assertSame('https://site.example/img.jpg', $entry->image->url);
        self::assertNull($entry->image->width);
        self::assertNull($entry->image->height);
    }

    public function testParsesDateGmtAsUtc(): void
    {
        $publishedAt = $this->parse(self::POST)->entries[0]->publishedAt;

        self::assertNotNull($publishedAt);
        self::assertSame('2026-08-20T14:23:15+00:00', $publishedAt->format('c'));
    }

    public function testTitleIsNullSiteLevel(): void
    {
        self::assertNull($this->parse(self::POST)->title);
    }

    public function testAPostWithoutJetpackImageHasNullImageAndAuthor(): void
    {
        $entry = $this->parse('[{"id":7,"link":"https://x.example/7","title":{"rendered":"T"}}]')
            ->entries[0];

        self::assertNull($entry->author);
        self::assertNull($entry->image);
        self::assertNull($entry->publishedAt);
        self::assertSame('7', $entry->guid);
    }

    public function testAnEmptyJetpackImageUrlIsNull(): void
    {
        $body = '[{"id":8,"link":"https://x.example/8","title":{"rendered":"T"},'
            . '"jetpack_featured_media_url":""}]';

        self::assertNull($this->parse($body)->entries[0]->image);
    }

    public function testFallsBackToTheContentLeadImageWhenNoJetpackImage(): void
    {
        // A Jetpack-less site (e.g. correctiv) carries its picture inline in the
        // body; without this fallback entry.imageUrl stays null and the magazine
        // distrusts the client-derived inline image.
        $body = '[{"id":9,"link":"https://x.example/9","title":{"rendered":"T"},'
            . '"content":{"rendered":"<p>Intro.</p>'
            . '<figure><img src=\"https://x.example/lead.jpg\" alt=\"\"></figure>"}}]';

        $image = $this->parse($body)->entries[0]->image;

        self::assertNotNull($image);
        self::assertSame('https://x.example/lead.jpg', $image->url);
    }

    public function testFallsBackToTheExcerptImageWhenContentHasNone(): void
    {
        $body = '[{"id":10,"link":"https://x.example/10","title":{"rendered":"T"},'
            . '"content":{"rendered":"<p>No picture here.</p>"},'
            . '"excerpt":{"rendered":"<img src=\"https://x.example/teaser.jpg\" alt=\"\">"}}]';

        $image = $this->parse($body)->entries[0]->image;

        self::assertNotNull($image);
        self::assertSame('https://x.example/teaser.jpg', $image->url);
    }

    public function testTheContentImageWinsOverTheExcerptImage(): void
    {
        // Content is the fuller body, so its lead image ranks above the
        // excerpt's — the same content-before-summary order the RSS parsers use.
        $body = '[{"id":12,"link":"https://x.example/12","title":{"rendered":"T"},'
            . '"content":{"rendered":"<img src=\"https://x.example/from-content.jpg\" alt=\"\">"},'
            . '"excerpt":{"rendered":"<img src=\"https://x.example/from-excerpt.jpg\" alt=\"\">"}}]';

        self::assertSame(
            'https://x.example/from-content.jpg',
            $this->parse($body)->entries[0]->image?->url,
        );
    }

    public function testJetpackImageWinsOverTheContentLeadImage(): void
    {
        $body = '[{"id":11,"link":"https://x.example/11","title":{"rendered":"T"},'
            . '"content":{"rendered":"<img src=\"https://x.example/inline.jpg\" alt=\"\">"},'
            . '"jetpack_featured_media_url":"https://x.example/featured.jpg"}]';

        self::assertSame(
            'https://x.example/featured.jpg',
            $this->parse($body)->entries[0]->image?->url,
        );
    }

    public function testEmptyArrayIsAZeroEntryFeed(): void
    {
        self::assertSame([], $this->parse('[]')->entries);
    }

    public function testNonArrayBodyThrows(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parse('{"not":"a list"}');
    }

    public function testEmptyBodyThrows(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parse('   ');
    }

    public function testWhitespacePaddedBodyStillParses(): void
    {
        // A vertical tab is not JSON-insignificant whitespace, so json_decode()
        // alone rejects it; only the explicit trim() strips it before decoding.
        $entry = $this->parse("\x0B" . self::POST . "\x0B")->entries[0];

        self::assertSame('https://site.example/?p=101', $entry->guid);
    }

    public function testTitleEntityDecodingUsesEntQuotesAndTrimsWhitespace(): void
    {
        // The whitespace sits INSIDE the tag boundary, so it only surfaces once
        // strip_tags() removes the <em> — stringOrNull()'s own trim on the raw
        // "rendered" string cannot touch it, isolating plainTitle()'s own trim.
        $body = '[{"id":1,"link":"https://x.example/1",'
            . '"title":{"rendered":"<em>  Ben &#039;s pick  </em>"}}]';

        self::assertSame("Ben 's pick", $this->parse($body)->entries[0]->title);
    }

    public function testAWhitespaceOnlyGuidFallsBackToId(): void
    {
        $body = '[{"id":42,"link":"https://x.example/42",'
            . '"guid":{"rendered":"   "},"title":{"rendered":"T"}}]';

        self::assertSame('42', $this->parse($body)->entries[0]->guid);
    }

    public function testAPostWithNeitherGuidNorIdFallsBackToLink(): void
    {
        $body = '[{"link":"https://x.example/only-link","title":{"rendered":"T"}}]';

        self::assertSame('https://x.example/only-link', $this->parse($body)->entries[0]->guid);
    }

    public function testNonArrayItemsInTheListAreSkipped(): void
    {
        $body = '[{"id":1,"link":"https://x.example/1","title":{"rendered":"One"}},'
            . '"junk",42,'
            . '{"id":2,"link":"https://x.example/2","title":{"rendered":"Two"}}]';

        $entries = $this->parse($body)->entries;

        self::assertCount(2, $entries);
        self::assertSame('1', $entries[0]->guid);
        self::assertSame('2', $entries[1]->guid);
    }
}

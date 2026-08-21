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
}

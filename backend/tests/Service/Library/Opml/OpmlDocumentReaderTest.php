<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Opml;

use App\Enum\SourceFormat;
use App\Service\Library\Opml\Exception\InvalidOpmlException;
use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFolderModel;
use App\Service\Library\Opml\OpmlBodyReader;
use App\Service\Library\Opml\OpmlDocumentReader;
use PHPUnit\Framework\TestCase;

final class OpmlDocumentReaderTest extends TestCase
{
    private function reader(): OpmlDocumentReader
    {
        return new OpmlDocumentReader(new OpmlBodyReader());
    }

    private function opml(string $bodyInner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<opml version="2.0"><head><title>t</title></head><body>'
            . $bodyInner
            . '</body></opml>';
    }

    private function read(string $bodyInner): OpmlFolderModel
    {
        return $this->reader()->read($this->opml($bodyInner));
    }

    public function testAFeedCarriesItsUrlTitleAndFormat(): void
    {
        $root = $this->read('<outline type="rss" text=" Example " xmlUrl=" https://a.example/feed.xml "/>');

        self::assertNull($root->name);
        self::assertEquals(
            [new OpmlFeedModel('https://a.example/feed.xml', 'Example', SourceFormat::XML)],
            $root->feeds,
        );
    }

    public function testAnOutlineCarriesItsSiteUrl(): void
    {
        $root = $this->read(
            '<outline type="rss" text="always beta" title="always beta" xmlUrl="https://alwaysbeta.de/feed/"'
            . ' htmlUrl="https://alwaysbeta.de/" />',
        );

        self::assertSame('https://alwaysbeta.de/', $root->feeds[0]->siteUrl);
    }

    public function testANonHttpSiteUrlIsDropped(): void
    {
        $root = $this->read('<outline xmlUrl="https://a.example/feed" htmlUrl="javascript:alert(1)"/>');

        self::assertNull($root->feeds[0]->siteUrl);
    }

    public function testAMissingSiteUrlIsNull(): void
    {
        $root = $this->read('<outline xmlUrl="https://a.example/feed"/>');

        self::assertNull($root->feeds[0]->siteUrl);
    }

    public function testABlankTextFallsBackToTheTitleAndNeitherIsNull(): void
    {
        $root = $this->read(
            '<outline text=" " title="Titled" xmlUrl="https://a.example/1"/>'
            . '<outline xmlUrl="https://a.example/2"/>',
        );

        self::assertSame(
            ['Titled', null],
            array_map(static fn (OpmlFeedModel $feed): ?string => $feed->title, $root->feeds),
        );
    }

    public function testATitleIsCutToTheFeedColumn(): void
    {
        $root = $this->read('<outline text="' . str_repeat('é', 513) . '" xmlUrl="https://a.example/1"/>');

        self::assertSame(512, mb_strlen((string) $root->feeds[0]->title));
    }

    public function testAFolderHoldsItsFeedsAndItsFolders(): void
    {
        $root = $this->read(
            '<outline text="Reading"><outline text="F" xmlUrl="https://a.example/1"/>'
            . '<outline title="Long reads"><outline text="G" xmlUrl="https://a.example/2"/></outline></outline>',
        );

        self::assertSame([], $root->feeds);
        self::assertSame('Reading', $root->folders[0]->name);
        self::assertSame('https://a.example/1', $root->folders[0]->feeds[0]->url);
        self::assertSame('Long reads', $root->folders[0]->folders[0]->name);
        self::assertSame('https://a.example/2', $root->folders[0]->folders[0]->feeds[0]->url);
    }

    public function testANamelessGroupPassesItsFeedsAndFoldersToTheFolderAbove(): void
    {
        $root = $this->read(
            '<outline text="Reading"><outline><outline text="F" xmlUrl="https://a.example/1"/>'
            . '<outline text="Inner"/></outline></outline>',
        );

        self::assertSame('https://a.example/1', $root->folders[0]->feeds[0]->url);
        self::assertSame('Inner', $root->folders[0]->folders[0]->name);
    }

    public function testNamelessGroupsAtTheRootPassTheirFeedsToTheRoot(): void
    {
        $root = $this->read(
            '<outline><outline><outline text="F" xmlUrl="https://a.example/1"/></outline></outline>',
        );

        self::assertSame(
            ['https://a.example/1'],
            array_map(static fn (OpmlFeedModel $feed): string => $feed->url, $root->feeds),
        );
        self::assertSame([], $root->folders);
    }

    public function testAFeedOutlineIgnoresItsChildren(): void
    {
        $root = $this->read(
            '<outline text="F" xmlUrl="https://a.example/1">'
            . '<outline text="G" xmlUrl="https://a.example/2"/></outline>',
        );

        self::assertCount(1, $root->feeds);
    }

    public function testAUrlListedTwiceStaysUnderItsFirstFolder(): void
    {
        $root = $this->read(
            '<outline text="A"><outline text="F" xmlUrl="https://a.example/1"/></outline>'
            . '<outline text="B"><outline text="F" xmlUrl="https://a.example/1"/></outline>',
        );

        self::assertCount(1, $root->folders[0]->feeds);
        self::assertSame([], $root->folders[1]->feeds);
    }

    public function testAUrlDifferingOnlyBySurroundingWhitespaceCountsAsTheSame(): void
    {
        $root = $this->read(
            '<outline text="F" xmlUrl="https://a.example/1"/>'
            . '<outline text="G" xmlUrl="  https://a.example/1 "/>',
        );

        self::assertCount(1, $root->feeds);
    }

    public function testADeclaredVerbatimFormatReadsAsThatFormat(): void
    {
        $root = $this->read(
            '<outline type="rss" text="Blog" xmlUrl="https://blog.example.com/wp-json/wp/v2/posts"'
            . ' sourceFormat="wp-json"/>',
        );

        self::assertSame(SourceFormat::WP_JSON, $root->feeds[0]->sourceFormat);
    }

    public function testADeclaredFormatThatIsNotVerbatimReadsAsXml(): void
    {
        $root = $this->read(
            '<outline type="rss" text="S" xmlUrl="https://social.example.com/feed"'
            . ' sourceFormat="bluesky-api"/>',
        );

        self::assertSame(SourceFormat::XML, $root->feeds[0]->sourceFormat);
    }

    public function testADeclaredFormatWinsOverTheCloudcastsUrl(): void
    {
        $root = $this->read(
            '<outline type="rss" text="JD" xmlUrl="https://api.mixcloud.com/johndigweed/cloudcasts/"'
            . ' sourceFormat="xml"/>',
        );

        self::assertSame(SourceFormat::XML, $root->feeds[0]->sourceFormat);
    }

    public function testADeclaredFormatIsTrimmed(): void
    {
        $root = $this->read(
            '<outline type="rss" text="Blog" xmlUrl="https://blog.example.com/wp-json/wp/v2/posts"'
            . ' sourceFormat=" wp-json "/>',
        );

        self::assertSame(SourceFormat::WP_JSON, $root->feeds[0]->sourceFormat);
    }

    public function testACloudcastsUrlWithoutAFormatReadsAsMixcloud(): void
    {
        $root = $this->read(
            '<outline type="rss" text="JD" xmlUrl="https://api.mixcloud.com/johndigweed/cloudcasts/"/>',
        );

        self::assertSame(SourceFormat::MIXCLOUD_API, $root->feeds[0]->sourceFormat);
    }

    public function testAnEmptyBodyIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->reader()->read('');
    }

    public function testABodyOverOneMegabyteIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->reader()->read(str_repeat('a', 1_048_577));
    }

    public function testABodyOfExactlyOneMegabyteReachesTheParser(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('Not a well-formed OPML 2.0 document.');

        $this->reader()->read(str_repeat('a', 1_048_576));
    }

    public function testRejectsNonOpml(): void
    {
        $this->expectException(InvalidOpmlException::class);

        $this->reader()->read(/** @lang TEXT */ '<html><body>not opml</body></html>');
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(InvalidOpmlException::class);

        $this->reader()->read('this is { not xml');
    }

    public function testRejectsDtd(): void
    {
        $this->expectException(InvalidOpmlException::class);

        $this->reader()->read(
            '<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY z "zz">]><opml version="2.0"><body/></opml>',
        );
    }
}

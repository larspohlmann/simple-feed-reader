<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ingestion\Parser\Factory\FeedParserFactory;
use App\Service\Ingestion\Parser\FeedFormatParser\Atom03Parser;
use App\Service\Ingestion\Parser\FeedFormatParser\Atom10Parser;
use App\Service\Ingestion\Parser\FeedFormatParser\FeedFormatParserInterface;
use App\Service\Ingestion\Parser\FeedFormatParser\Rss1Parser;
use App\Service\Ingestion\Parser\FeedFormatParser\Rss2Parser;
use App\Service\Ingestion\Parser\FeedItemImageSelector;
use App\Service\Ingestion\Parser\FeedParser;
use App\Service\Ingestion\Parser\ItemImageExtractor;
use App\Service\Ingestion\Parser\ItemMediaExtractor;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;

/** The format parsers over the real image and media policies, wired as the container wires them. */
final class FeedFormatParsers
{
    private function __construct()
    {
    }

    public static function rss2(): Rss2Parser
    {
        return new Rss2Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function rss1(): Rss1Parser
    {
        return new Rss1Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function atom10(): Atom10Parser
    {
        return new Atom10Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function atom03(): Atom03Parser
    {
        return new Atom03Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    /** @return list<FeedFormatParserInterface> */
    public static function all(): array
    {
        return [self::rss2(), self::atom10(), self::atom03(), self::rss1()];
    }

    public static function feed(string $xml): ParsedFeedModel
    {
        return new FeedParser(new FeedParserFactory(self::all()))->parse($xml);
    }

    /** @return list<string> */
    public static function entryTitles(ParsedFeedModel $feed): array
    {
        return array_map(static fn (ParsedEntryModel $entry): string => $entry->headline->text, $feed->entries);
    }

    private static function imageSelector(): FeedItemImageSelector
    {
        return new FeedItemImageSelector(new ItemImageExtractor());
    }
}

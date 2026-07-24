<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\ParsedFeed;
use App\Service\Scraper\HtmlItemExtractor;

/**
 * Refresh strategy for feeds synthesized from a plain HTML page ('scraped'
 * candidates the user subscribed). Extraction failures surface as
 * HtmlExtractionException, a FeedParseException subtype, so the runner's
 * existing parse-failure handling (recordFailure, backoff, Erroring status)
 * applies to scraped feeds unchanged.
 */
final readonly class ScrapedBodyParser implements FeedBodyParserInterface
{
    public function __construct(private HtmlItemExtractor $extractor)
    {
    }

    public static function format(): string
    {
        return SourceFormat::SCRAPED;
    }

    public function parse(string $body, Feed $feed): ParsedFeed
    {
        // The feed's stored URL is the page's canonical address — it anchors
        // relative article links exactly as it did at discovery time.
        return $this->extractor->extract($body, $feed->getUrl());
    }
}

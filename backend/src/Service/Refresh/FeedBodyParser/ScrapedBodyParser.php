<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Enum\SourceFormat;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Scraper\HtmlItemExtractor;

/**
 * Refresh strategy for feeds synthesized from an HTML page. HtmlExtractionException is a FeedParseException, so a
 * scraped feed fails, backs off and turns Erroring exactly like an xml one.
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

    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->extractor->extract($body, $feedUrl);
    }
}

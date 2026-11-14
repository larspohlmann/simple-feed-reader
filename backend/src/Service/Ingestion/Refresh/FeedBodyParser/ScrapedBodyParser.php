<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\FeedBodyParser;

use App\Enum\SourceFormat;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Service\Ingestion\Scraper\HtmlItemExtractor;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Refresh strategy for feeds synthesized from an HTML page. HtmlExtractionException is a FeedParseException, so a
 * scraped feed fails, backs off and turns Erroring exactly like an xml one.
 */
#[AsTaggedItem(index: SourceFormat::SCRAPED)]
final readonly class ScrapedBodyParser implements FeedBodyParserInterface
{
    public function __construct(private HtmlItemExtractor $extractor)
    {
    }

    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->extractor->extract($body, $feedUrl);
    }
}

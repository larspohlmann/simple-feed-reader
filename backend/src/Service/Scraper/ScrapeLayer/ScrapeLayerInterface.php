<?php

declare(strict_types=1);

namespace App\Service\Scraper\ScrapeLayer;

use App\Service\Scraper\Model\ScrapedItemModel;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One extraction strategy for feedless HTML pages. Implementations are tagged
 * automatically and collected by HtmlItemExtractor in AsTaggedItem priority
 * order, highest first — new strategies only need to implement this interface.
 */
#[AutoconfigureTag('app.scrape_layer')]
interface ScrapeLayerInterface
{
    /** @return list<ScrapedItemModel> */
    public function extract(HTMLDocument $doc, string $baseUrl): array;
}

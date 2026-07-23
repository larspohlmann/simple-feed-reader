<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Scraper\ScrapedItem;
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
    /** @return list<ScrapedItem> */
    public function extract(HTMLDocument $doc, string $baseUrl): array;
}

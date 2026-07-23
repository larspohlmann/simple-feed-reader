<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Scraper\ScrapedItem;

interface ScrapeLayerInterface
{
    /** @return list<ScrapedItem> */
    public function extract(\Dom\HTMLDocument $doc, string $baseUrl): array;
}

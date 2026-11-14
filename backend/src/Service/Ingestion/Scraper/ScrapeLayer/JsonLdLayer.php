<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Scraper\ScrapeLayer;

use App\Service\Outbound\Fetch\Pass\PageUrls;
use App\Service\Foundation\Html\Support\JsonLd;
use App\Service\Ingestion\Scraper\Pass\JsonLdArticles;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** The most trusted scrape layer: the articles a page's JSON-LD blocks describe. */
#[AsTaggedItem(priority: 30)]
final readonly class JsonLdLayer implements ScrapeLayerInterface
{
    public function extract(HTMLDocument $document, string $baseUrl): array
    {
        $articles = new JsonLdArticles(new PageUrls($baseUrl));
        foreach (JsonLd::scriptsIn($document) as $script) {
            $articles->collect(JsonLd::decode($script));
            if ($articles->isFull()) {
                break;
            }
        }

        return $articles->all();
    }
}

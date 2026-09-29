<?php

declare(strict_types=1);

namespace App\Service\Scraper\ScrapeLayer;

use App\Service\Fetch\Pass\PageUrls;
use App\Service\Html\Support\JsonLd;
use App\Service\Scraper\Pass\JsonLdArticles;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Extracts items from JSON-LD blocks: ItemList structures (with ListItems
 * carrying either a full article node or bare url/name), plain article nodes
 * (NewsArticle, BlogPosting, Article), and @graph wrappers around either.
 * Non-article structured data (Organization, BreadcrumbList, …) is ignored.
 *
 * The layer reads the blocks; JsonLdArticles walks what they decode to.
 */
#[AsTaggedItem(priority: 30)]
final class JsonLdLayer implements ScrapeLayerInterface
{
    public function extract(HTMLDocument $doc, string $baseUrl): array
    {
        $articles = new JsonLdArticles(new PageUrls($baseUrl));
        foreach (JsonLd::scriptsIn($doc) as $script) {
            $articles->collect(JsonLd::decode($script));
            if ($articles->isFull()) {
                break;
            }
        }

        return $articles->all();
    }
}

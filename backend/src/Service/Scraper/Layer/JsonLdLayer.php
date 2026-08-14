<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Fetch\PageUrls;
use App\Service\Scraper\JsonLdArticles;
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
        foreach ($doc->querySelectorAll('script[type="application/ld+json"]') as $script) {
            try {
                $decoded = json_decode($script->textContent ?? '', true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($decoded)) {
                continue;
            }
            $articles->collect($decoded);
            if ($articles->isFull()) {
                break;
            }
        }

        return $articles->all();
    }
}

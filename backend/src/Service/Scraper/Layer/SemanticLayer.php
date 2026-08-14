<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Fetch\PageUrls;
use App\Service\Scraper\CardFields;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Extracts items from pages that mark their listing up semantically: three or
 * more article elements, each treated as one card around its first link.
 */
#[AsTaggedItem(priority: 20)]
final class SemanticLayer implements ScrapeLayerInterface
{
    private const int MIN_ARTICLES = 3;

    public function extract(HTMLDocument $doc, string $baseUrl): array
    {
        $articles = $doc->querySelectorAll('article');
        if (\count($articles) < self::MIN_ARTICLES) {
            return [];
        }

        $cardFields = new CardFields(new PageUrls($baseUrl));

        $items = [];
        foreach ($articles as $article) {
            $anchor = $article->querySelector('a[href]');
            if (!$anchor instanceof Element) {
                continue;
            }
            $item = $cardFields->item($article, $anchor);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }
}

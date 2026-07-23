<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Scraper\CardFields;

/**
 * Extracts items from pages that mark their listing up semantically: three or
 * more article elements, each treated as one card around its first link.
 */
final class SemanticLayer implements ScrapeLayerInterface
{
    private const int MIN_ARTICLES = 3;

    public function extract(\Dom\HTMLDocument $doc, string $baseUrl): array
    {
        $articles = $doc->querySelectorAll('article');
        if (\count($articles) < self::MIN_ARTICLES) {
            return [];
        }

        $items = [];
        foreach ($articles as $article) {
            $anchor = $article->querySelector('a[href]');
            if (!$anchor instanceof \Dom\Element) {
                continue;
            }
            $item = CardFields::item($article, $anchor, $baseUrl);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }
}

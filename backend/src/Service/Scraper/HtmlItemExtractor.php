<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Parser\GuidFallback;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Scraper\Exception\HtmlExtractionException;
use App\Service\Scraper\Layer\ScrapeLayerInterface;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Synthesizes a ParsedFeed from a feedless HTML page. The heuristic layers
 * run in order of trustworthiness — JSON-LD, semantic article markup, anchor
 * clustering — and the first one that survives the guards wins: at least
 * three items after dropping self-links (url equal to the page) and URL
 * duplicates, capped at fifty entries. Pages where no layer succeeds throw
 * HtmlExtractionException, a FeedParseException subtype, so the existing
 * refresh error handling applies. New extraction strategies (microformats,
 * site-specific layers, …) plug in by implementing ScrapeLayerInterface —
 * the app.scrape_layer tag collects them here in AsTaggedItem priority order.
 */
final readonly class HtmlItemExtractor
{
    private const int MIN_ITEMS = 3;
    private const int MAX_ITEMS = 50;

    /** @param iterable<ScrapeLayerInterface> $layers */
    public function __construct(
        #[AutowireIterator('app.scrape_layer')]
        private iterable $layers,
    ) {
    }

    public function extract(string $html, string $baseUrl): ParsedFeed
    {
        $doc = $this->parse($html);
        $entries = array_map(
            fn (ScrapedItem $item): ParsedEntry => $this->toEntry($item),
            \array_slice($this->firstSuccessfulLayer($doc, $baseUrl), 0, self::MAX_ITEMS),
        );

        return new ParsedFeed($this->feedTitle($doc), $baseUrl, $this->metaDescription($doc), $entries);
    }

    private function parse(string $html): HTMLDocument
    {
        if (trim($html) === '') {
            throw new HtmlExtractionException('The page is empty.');
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable $e) {
            throw new HtmlExtractionException('The page could not be parsed as HTML.', 0, $e);
        }
    }

    /** @return list<ScrapedItem> */
    private function firstSuccessfulLayer(HTMLDocument $doc, string $baseUrl): array
    {
        foreach ($this->layers as $layer) {
            $items = $this->guarded($layer->extract($doc, $baseUrl), $baseUrl);
            if (\count($items) >= self::MIN_ITEMS) {
                return $items;
            }
        }

        throw new HtmlExtractionException('No article list was detected on the page.');
    }

    /**
     * Drops items linking back to the page itself and URL duplicates (first
     * occurrence wins), keeping document order.
     *
     * @param list<ScrapedItem> $items
     * @return list<ScrapedItem>
     */
    private function guarded(array $items, string $baseUrl): array
    {
        $self = rtrim($baseUrl, '/');
        $unique = [];
        foreach ($items as $item) {
            if (rtrim($item->url, '/') === $self || isset($unique[$item->url])) {
                continue;
            }
            $unique[$item->url] = $item;
        }

        return array_values($unique);
    }

    private function feedTitle(HTMLDocument $doc): ?string
    {
        $candidates = [
            $doc->querySelector('meta[property="og:site_name"]')?->getAttribute('content'),
            $doc->querySelector('title')?->textContent,
        ];
        foreach ($candidates as $candidate) {
            $candidate = TextNormalizer::normalize($candidate ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function metaDescription(HTMLDocument $doc): ?string
    {
        $description = $doc->querySelector('meta[name="description"]')?->getAttribute('content');
        $description = TextNormalizer::normalize($description ?? '');

        return $description === '' ? null : $description;
    }

    private function toEntry(ScrapedItem $item): ParsedEntry
    {
        return new ParsedEntry(
            guid: GuidFallback::for($item->url, $item->url, $item->title),
            url: $item->url,
            title: $item->title,
            author: null,
            summary: $item->teaser,
            contentHtml: $item->teaser === null
                ? null
                : '<p>' . htmlspecialchars($item->teaser, \ENT_QUOTES) . '</p>',
            publishedAt: $item->publishedAt,
            imageUrl: $item->imageUrl,
        );
    }
}

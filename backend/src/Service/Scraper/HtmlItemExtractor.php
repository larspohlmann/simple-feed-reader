<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\GuidFallback;
use App\Service\Scraper\Exception\HtmlExtractionException;
use App\Service\Scraper\Model\ScrapedItemModel;
use App\Service\Scraper\Pass\CardFields;
use App\Service\Scraper\ScrapeLayer\ScrapeLayerInterface;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Synthesises a feed from a feedless HTML page. The `app.scrape_layer` layers run most trustworthy first, and the
 * first to keep MIN_ITEMS after dropping self-links and duplicate URLs wins. With none, HtmlExtractionException, a
 * FeedParseException, lets the refresh error handling apply.
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

    public function extract(string $html, string $baseUrl): ParsedFeedModel
    {
        $document = $this->parse($html);
        $entries = array_map(
            fn (ScrapedItemModel $item): ParsedEntryModel => $this->toEntry($item),
            \array_slice($this->firstSuccessfulLayer($document, $baseUrl), 0, self::MAX_ITEMS),
        );

        // No feed image: og:image is the page's picture, not the site's mark,
        // so guessing with it would put an article photo in the feed header.
        return new ParsedFeedModel(
            $this->feedTitle($document),
            $baseUrl,
            $this->metaDescription($document),
            null,
            $entries,
        );
    }

    private function parse(string $html): HTMLDocument
    {
        if (trim($html) === '') {
            throw new HtmlExtractionException('The page is empty.');
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable $exception) {
            throw new HtmlExtractionException('The page could not be parsed as HTML.', 0, $exception);
        }
    }

    /** @return list<ScrapedItemModel> */
    private function firstSuccessfulLayer(HTMLDocument $document, string $baseUrl): array
    {
        foreach ($this->layers as $layer) {
            $items = $this->guarded($layer->extract($document, $baseUrl), $baseUrl);
            if (\count($items) >= self::MIN_ITEMS) {
                return $items;
            }
        }

        throw new HtmlExtractionException('No article list was detected on the page.');
    }

    /**
     * @param list<ScrapedItemModel> $items
     * @return list<ScrapedItemModel>
     */
    private function guarded(array $items, string $baseUrl): array
    {
        $self = rtrim($baseUrl, '/');
        $unique = [];
        foreach ($items as $item) {
            if (isset($unique[$item->url]) || rtrim($item->url, '/') === $self) {
                continue;
            }
            $unique[$item->url] = $item;
        }

        return array_values($unique);
    }

    private function feedTitle(HTMLDocument $document): ?string
    {
        $candidates = [
            $document->querySelector('meta[property="og:site_name"]')?->getAttribute('content'),
            $document->querySelector('title')?->textContent,
        ];
        foreach ($candidates as $candidate) {
            $candidate = TextNormalizer::normalize($candidate ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function metaDescription(HTMLDocument $document): ?string
    {
        $description = $document->querySelector('meta[name="description"]')?->getAttribute('content');
        $description = TextNormalizer::normalize($description ?? '');

        return $description === '' ? null : $description;
    }

    private function toEntry(ScrapedItemModel $item): ParsedEntryModel
    {
        // The teaser cap applies here, at the funnel every layer's output passes, so no layer's teasers escape it.
        $teaser = $item->teaser === null
            ? null
            : mb_substr($item->teaser, 0, CardFields::MAX_TEASER_LENGTH);

        return new ParsedEntryModel(
            guid: GuidFallback::for($item->url, $item->url, $item->title),
            url: $item->url,
            title: $item->title,
            author: null,
            summary: $teaser,
            contentHtml: $teaser === null
                ? null
                : '<p>' . htmlspecialchars($teaser, \ENT_QUOTES) . '</p>',
            publishedAt: $item->publishedAt,
            media: new ParsedEntryMediaModel($item->imageUrl === null ? null : new DeclaredImageModel($item->imageUrl)),
        );
    }
}

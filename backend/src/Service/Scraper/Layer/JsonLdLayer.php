<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Parser\DateParser;
use App\Service\Scraper\CardFields;
use App\Service\Scraper\ScrapedItem;
use App\Service\Scraper\TextNormalizer;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Extracts items from JSON-LD blocks: ItemList structures (with ListItems
 * carrying either a full article node or bare url/name), plain article nodes
 * (NewsArticle, BlogPosting, Article), and @graph wrappers around either.
 * Non-article structured data (Organization, BreadcrumbList, …) is ignored.
 */
#[AsTaggedItem(priority: 30)]
final class JsonLdLayer implements ScrapeLayerInterface
{
    private const array ARTICLE_TYPES = ['NewsArticle', 'BlogPosting', 'Article'];

    public function extract(HTMLDocument $doc, string $baseUrl): array
    {
        $items = [];
        foreach ($doc->querySelectorAll('script[type="application/ld+json"]') as $script) {
            $decoded = json_decode($script->textContent ?? '', true);
            if (!\is_array($decoded)) {
                continue;
            }
            $items = [...$items, ...$this->collect($decoded, $baseUrl)];
        }

        return $items;
    }

    /**
     * @param array<mixed> $node
     * @return list<ScrapedItem>
     */
    private function collect(array $node, string $baseUrl): array
    {
        if (array_is_list($node)) {
            return $this->collectAll($node, $baseUrl);
        }
        $graph = $node['@graph'] ?? null;
        if (\is_array($graph)) {
            return $this->collectAll($graph, $baseUrl);
        }
        if ($this->hasType($node, 'ItemList')) {
            $elements = $node['itemListElement'] ?? null;

            return $this->listItems(\is_array($elements) ? $elements : [], $baseUrl);
        }
        if ($this->hasType($node, ...self::ARTICLE_TYPES)) {
            $item = $this->article($node, $baseUrl);

            return $item === null ? [] : [$item];
        }

        return [];
    }

    /**
     * @param array<mixed> $nodes
     * @return list<ScrapedItem>
     */
    private function collectAll(array $nodes, string $baseUrl): array
    {
        $items = [];
        foreach ($nodes as $node) {
            if (\is_array($node)) {
                $items = [...$items, ...$this->collect($node, $baseUrl)];
            }
        }

        return $items;
    }

    /**
     * A ListItem either wraps a full article node in "item" or carries bare
     * url/name fields itself; both shapes map through article(). Entries that
     * are not arrays (heise mixes bare URL strings into itemListElement) and
     * "item" references that are not article nodes are skipped silently.
     *
     * @param array<mixed> $elements
     * @return list<ScrapedItem>
     */
    private function listItems(array $elements, string $baseUrl): array
    {
        $items = [];
        foreach ($elements as $element) {
            if (!\is_array($element)) {
                continue;
            }
            $article = $element['item'] ?? $element;
            if (!\is_array($article)) {
                continue;
            }
            $item = $this->article($article, $baseUrl);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param array<mixed> $node */
    private function hasType(array $node, string ...$types): bool
    {
        $declared = $node['@type'] ?? null;
        $declared = \is_string($declared) ? [$declared] : $declared;
        if (!\is_array($declared)) {
            return false;
        }

        return array_intersect($types, array_filter($declared, \is_string(...))) !== [];
    }

    /** @param array<mixed> $node */
    private function article(array $node, string $baseUrl): ?ScrapedItem
    {
        $url = CardFields::httpUrl($this->url($node), $baseUrl);
        if ($url === null) {
            return null;
        }
        $title = $this->title($node);
        if ($title === null) {
            return null;
        }
        $published = $node['datePublished'] ?? null;

        return new ScrapedItem(
            url: $url,
            title: $title,
            teaser: $this->teaser($node),
            imageUrl: CardFields::httpUrl($this->imageCandidate($node), $baseUrl),
            publishedAt: DateParser::parse(\is_string($published) ? $published : null),
        );
    }

    /** @param array<mixed> $node */
    private function url(array $node): ?string
    {
        $url = $node['url'] ?? null;
        if (\is_string($url)) {
            return $url;
        }
        $main = $node['mainEntityOfPage'] ?? null;
        if (\is_array($main)) {
            $main = $main['@id'] ?? null;
        }

        return \is_string($main) ? $main : null;
    }

    /** @param array<mixed> $node */
    private function title(array $node): ?string
    {
        $raw = $node['headline'] ?? $node['name'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }
        $title = TextNormalizer::normalize($raw);
        if (mb_strlen($title) < CardFields::MIN_TITLE_LENGTH) {
            return null;
        }

        return mb_substr($title, 0, CardFields::MAX_TITLE_LENGTH);
    }

    /** @param array<mixed> $node */
    private function teaser(array $node): ?string
    {
        // Most sites use "description"; heise ships its teasers as "abstract".
        foreach ([$node['description'] ?? null, $node['abstract'] ?? null] as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }
            $teaser = TextNormalizer::normalize($candidate);
            if (mb_strlen($teaser) >= CardFields::MIN_TEASER_LENGTH) {
                return $teaser;
            }
        }

        return null;
    }

    /**
     * Accepts every image shape schema.org allows here: a URL string, an
     * ImageObject with a url field, or a list of either.
     *
     * @param array<mixed> $node
     */
    private function imageCandidate(array $node): ?string
    {
        $image = $node['image'] ?? null;
        if (\is_array($image) && array_is_list($image)) {
            $image = $image[0] ?? null;
        }
        if (\is_array($image)) {
            $image = $image['url'] ?? null;
        }

        return \is_string($image) ? $image : null;
    }
}

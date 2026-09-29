<?php

declare(strict_types=1);

namespace App\Service\Scraper\Pass;

use App\Service\Fetch\Pass\PageUrls;
use App\Service\Parser\Support\DateParser;
use App\Service\Scraper\Model\ScrapedItemModel;
use App\Service\Scraper\Support\TextNormalizer;

/** The articles a page's JSON-LD describes, found by recursing through lists, @graph and ItemList entries. */
final class JsonLdArticles
{
    private const array ARTICLE_TYPES = ['NewsArticle', 'BlogPosting', 'Article'];

    /**
     * Bounds the walk over a pathological @graph of tens of thousands of nodes. Real pages stay well below it (190
     * ItemList entries on the largest seen), and the extractor keeps only 50.
     */
    private const int MAX_COLLECT = 200;

    /** @var list<ScrapedItemModel> */
    private array $items = [];

    public function __construct(private readonly PageUrls $pageUrls)
    {
    }

    /**
     * Appending never spreads a growing array, so collection stays O(N).
     *
     * @param array<mixed> $node
     */
    public function collect(array $node): void
    {
        if ($this->isFull()) {
            return;
        }
        if (array_is_list($node)) {
            $this->collectAll($node);

            return;
        }
        $graph = $node['@graph'] ?? null;
        if (\is_array($graph)) {
            $this->collectAll($graph);

            return;
        }
        if ($this->hasType($node, 'ItemList')) {
            $elements = $node['itemListElement'] ?? null;
            $this->collectListItems(\is_array($elements) ? $elements : []);

            return;
        }
        if ($this->hasType($node, ...self::ARTICLE_TYPES)) {
            $this->add($node);
        }
    }

    /** Whether the cap is reached, so the caller can stop decoding further blocks. */
    public function isFull(): bool
    {
        return \count($this->items) >= self::MAX_COLLECT;
    }

    /** @return list<ScrapedItemModel> */
    public function all(): array
    {
        return $this->items;
    }

    /** @param array<mixed> $nodes */
    private function collectAll(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($this->isFull()) {
                return;
            }
            if (\is_array($node)) {
                $this->collect($node);
            }
        }
    }

    /**
     * A ListItem wraps an article node in "item" or carries url and name itself. A non-array entry, such as a bare URL
     * string, and an "item" that is not an article node are skipped.
     *
     * @param array<mixed> $elements
     */
    private function collectListItems(array $elements): void
    {
        foreach ($elements as $element) {
            if ($this->isFull()) {
                return;
            }
            if (!\is_array($element)) {
                continue;
            }
            $article = $element['item'] ?? $element;
            if (!\is_array($article)) {
                continue;
            }
            $this->add($article);
        }
    }

    /** @param array<mixed> $node */
    private function add(array $node): void
    {
        $item = $this->article($node);
        if ($item !== null) {
            $this->items[] = $item;
        }
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
    private function article(array $node): ?ScrapedItemModel
    {
        $url = $this->pageUrls->httpUrl($this->url($node));
        if ($url === null) {
            return null;
        }
        $title = $this->title($node);
        if ($title === null) {
            return null;
        }
        $published = $node['datePublished'] ?? null;

        return new ScrapedItemModel(
            url: $url,
            title: $title,
            teaser: $this->teaser($node),
            imageUrl: $this->pageUrls->httpUrl($this->imageCandidate($node)),
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
        // "description" first, then schema.org's "abstract", which some sites use for teasers instead.
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

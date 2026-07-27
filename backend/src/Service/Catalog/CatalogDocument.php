<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Enum\SourceFormat;
use App\Exception\InvalidOpmlException;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Opml\OpmlBodyReader;

/**
 * Parses and fully validates a catalog OPML document.
 *
 * Shape: one level of group outlines, each a category, each containing only feed
 * outlines. A group outline carries the standard `text` plus three extra
 * attributes OPML has no equivalent for — `key`, `icon`, `color` — which OPML
 * 2.0 permits. A feed outline uses the standard `xmlUrl`, `htmlUrl` and
 * `description`.
 *
 * Validation happens here, at the boundary, so the importer can assume every
 * field is sound and an invalid document is rejected before a single row is
 * touched. There is no partial import.
 */
final readonly class CatalogDocument
{
    public function __construct(
        private OpmlBodyReader $bodyReader,
    ) {
    }

    public function parse(string $opml): ParsedCatalog
    {
        try {
            $body = $this->bodyReader->read($opml);
        } catch (InvalidOpmlException $e) {
            throw new InvalidCatalogDocumentException($e->getMessage(), 0, $e);
        }

        $categories = [];
        $seenKeys = [];
        $seenUrls = [];
        foreach ($this->outlines($body) as $outline) {
            $category = $this->category($outline, $seenUrls);
            if (isset($seenKeys[$category->key])) {
                throw new InvalidCatalogDocumentException(\sprintf('Duplicate category key "%s".', $category->key));
            }
            $seenKeys[$category->key] = true;
            $categories[] = $category;
        }

        if ([] === $categories) {
            throw new InvalidCatalogDocumentException('A catalog with no categories would empty the picker.');
        }

        return new ParsedCatalog($categories);
    }

    /**
     * @return list<\DOMElement>
     */
    private function outlines(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && 'outline' === $child->localName) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param array<string, true> $seenUrls carried across categories: a URL is unique in the whole document
     */
    private function category(\DOMElement $outline, array &$seenUrls): CatalogDocumentCategory
    {
        if ('' !== trim($outline->getAttribute('xmlUrl'))) {
            throw new InvalidCatalogDocumentException(
                'A feed cannot sit at the top level; it must be inside a category.',
            );
        }

        $key = $this->pattern($outline, 'key', '/^[a-z0-9_]+$/', 64);
        $name = $this->text($outline, 'text', 100);
        $icon = $this->pattern($outline, 'icon', '/^[a-z0-9_]+$/', 64);
        $color = $this->pattern($outline, 'color', '/^#[0-9a-fA-F]{6}$/', 7);

        $feeds = [];
        foreach ($this->outlines($outline) as $child) {
            $feed = $this->feed($child);
            if (isset($seenUrls[$feed->url])) {
                throw new InvalidCatalogDocumentException(\sprintf('Duplicate feed URL "%s".', $feed->url));
            }
            $seenUrls[$feed->url] = true;
            $feeds[] = $feed;
        }

        return new CatalogDocumentCategory($key, $name, $icon, $color, $feeds);
    }

    private function feed(\DOMElement $outline): CatalogDocumentFeed
    {
        $url = trim($outline->getAttribute('xmlUrl'));
        if ('' === $url) {
            // A nested group, not a feed. Rejected rather than flattened: the
            // picker renders exactly one level, so silently absorbing a second
            // one would lose the grouping the author wrote.
            throw new InvalidCatalogDocumentException(
                'Nested categories are not supported; a category contains feeds only.',
            );
        }
        if (mb_strlen($url) > 750) {
            throw new InvalidCatalogDocumentException(\sprintf('Feed URL "%s" exceeds 750 characters.', $url));
        }
        if (1 !== preg_match('#^https?://#', $url)) {
            throw new InvalidCatalogDocumentException(\sprintf('Feed URL "%s" is not http(s).', $url));
        }

        $format = trim($outline->getAttribute('sourceFormat'));
        if ('' === $format) {
            $format = SourceFormat::XML;
        }
        if (!\in_array($format, [SourceFormat::XML, SourceFormat::SCRAPED], true)) {
            throw new InvalidCatalogDocumentException(\sprintf('Unknown sourceFormat "%s".', $format));
        }

        return new CatalogDocumentFeed(
            title: $this->text($outline, 'text', 200),
            url: $url,
            siteUrl: $this->optional($outline, 'htmlUrl', 750),
            description: $this->optional($outline, 'description', 255),
            sourceFormat: $format,
        );
    }

    /** `text` is the OPML standard; `title` is accepted as the common alias. */
    private function text(\DOMElement $outline, string $attribute, int $max): string
    {
        $value = trim($outline->getAttribute($attribute));
        if ('' === $value && 'text' === $attribute) {
            $value = trim($outline->getAttribute('title'));
        }
        if ('' === $value) {
            throw new InvalidCatalogDocumentException(\sprintf('Missing or empty "%s".', $attribute));
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" exceeds %d characters.', $attribute, $max));
        }

        return $value;
    }

    private function optional(\DOMElement $outline, string $attribute, int $max): ?string
    {
        $value = trim($outline->getAttribute($attribute));
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" exceeds %d characters.', $attribute, $max));
        }

        return $value;
    }

    private function pattern(\DOMElement $outline, string $attribute, string $pattern, int $max): string
    {
        $value = $this->text($outline, $attribute, $max);
        if (1 !== preg_match($pattern, $value)) {
            throw new InvalidCatalogDocumentException(\sprintf('"%s" value "%s" is malformed.', $attribute, $value));
        }

        return $value;
    }
}

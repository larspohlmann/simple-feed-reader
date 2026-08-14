<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Fetch\PageUrls;
use App\Service\Parser\DateParser;
use Dom\Element;

/**
 * Extracts ScrapedItem fields from one card container + its anchor.
 *
 * Bound to the page the cards were found on: every URL a card carries resolves
 * against it, so a layer builds one instance per pass instead of handing the
 * page URL to each field in turn.
 *
 * Field rules (per the scraper design spec):
 * - title: candidate order lives in CardTitle; length rules (min 5,
 *   truncate 300) are applied here.
 * - teaser: longest leaf-ish text block that is at least 40 chars and does
 *   not repeat the title; else a data-*description* attribute value on the
 *   container or a direct child.
 * - image: first img in the container (src, data-src, or first srcset URL).
 * - date: first time[datetime], parsed leniently.
 */
final readonly class CardFields
{
    public const int MIN_TITLE_LENGTH = 5;
    public const int MAX_TITLE_LENGTH = 300;
    public const int MIN_TEASER_LENGTH = 40;
    /** Applied once at the HtmlItemExtractor funnel — one cap for every layer's teasers. */
    public const int MAX_TEASER_LENGTH = 1000;

    /** Child tags that make an element a wrapper rather than a text block. */
    private const array NON_LEAF_CHILDREN = ['P', 'DIV', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'ARTICLE', 'SECTION'];

    public function __construct(private PageUrls $pageUrls)
    {
    }

    public function item(Element $container, Element $anchor): ?ScrapedItem
    {
        $url = $this->pageUrls->httpUrl($anchor->getAttribute('href'));
        if ($url === null) {
            return null;
        }

        $title = self::title($container, $anchor);
        if ($title === null) {
            return null;
        }

        return new ScrapedItem(
            url: $url,
            title: $title,
            teaser: self::teaser($container, $title),
            imageUrl: $this->image($container),
            publishedAt: self::publishedAt($container),
        );
    }

    private static function title(Element $container, Element $anchor): ?string
    {
        $title = CardTitle::of($container, $anchor);
        if ($title === null || mb_strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    private static function teaser(Element $container, string $title): ?string
    {
        $teaser = null;
        foreach ($container->querySelectorAll('p, div, span') as $element) {
            if (!self::isLeafish($element)) {
                continue;
            }
            $text = TextNormalizer::normalize($element->textContent ?? '');
            if (mb_strlen($text) < self::MIN_TEASER_LENGTH || str_contains($text, $title)) {
                continue;
            }
            if ($teaser === null || mb_strlen($text) > mb_strlen($teaser)) {
                $teaser = $text;
            }
        }
        return $teaser ?? self::attributeTeaser($container);
    }

    private static function isLeafish(Element $element): bool
    {
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if (\in_array($child->tagName, self::NON_LEAF_CHILDREN, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fallback for cards whose visible description element is empty and the
     * text ships in a data attribute: treehugger puts data-card-description
     * on a div nested inside the card link, so the container itself and every
     * descendant element are scanned, first match in document order wins.
     * Only data-* names qualify: ARIA attributes (aria-describedby) carry ID
     * references, not prose, and would surface as a teaser of element ids.
     */
    private static function attributeTeaser(Element $container): ?string
    {
        foreach ([$container, ...$container->querySelectorAll('*')] as $element) {
            foreach ($element->attributes as $attribute) {
                if (!str_starts_with($attribute->name, 'data-') || stripos($attribute->name, 'descri') === false) {
                    continue;
                }
                $value = TextNormalizer::normalize($attribute->value);
                if (mb_strlen($value) >= self::MIN_TEASER_LENGTH) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function image(Element $container): ?string
    {
        $img = $container->querySelector('img');
        if (!$img instanceof Element) {
            return null;
        }
        $candidate = self::nonEmpty($img->getAttribute('src'))
            ?? self::nonEmpty($img->getAttribute('data-src'))
            ?? self::srcsetFirst($img->getAttribute('srcset'));

        return $this->pageUrls->httpUrl($candidate);
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    private static function srcsetFirst(?string $srcset): ?string
    {
        if ($srcset === null) {
            return null;
        }
        preg_match('/\S+/', explode(',', $srcset)[0], $matches);

        return self::nonEmpty($matches[0] ?? null);
    }

    private static function publishedAt(Element $container): ?\DateTimeImmutable
    {
        $time = $container->querySelector('time[datetime]');
        if (!$time instanceof Element) {
            return null;
        }

        return DateParser::parse($time->getAttribute('datetime'));
    }
}
